<?php

namespace App\Console\Commands;

use App\Enums\PrinterPurpose;
use App\Printing\Contracts\ThermalPrinterTransport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WorkPrintAgentCommand extends Command
{
    protected $signature = 'print-agent:work {--once : Consulta una vez y termina} {--max-jobs=0 : Termina después de procesar esta cantidad; cero no limita}';

    protected $description = 'Consulta la cola remota e imprime trabajos térmicos desde la PC Windows';

    public function __construct(private readonly ThermalPrinterTransport $transport)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            [$url, $request] = $this->client();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $processed = 0;
        $maxJobs = max(0, (int) $this->option('max-jobs'));
        $interval = max(1, (int) config('thermal-printing.agent.poll_interval_seconds', 3));
        $this->info('Agente de impresión iniciado con transporte '.config('thermal-printing.agent.transport').'.');

        while (true) {
            $hadError = false;
            try {
                $response = $request->post($url.'/api/print-agent/jobs/claim');
                if ($response->status() === 204) {
                    if ($this->option('once')) {
                        return self::SUCCESS;
                    }

                    sleep($interval);

                    continue;
                }

                $response->throw();
                $job = $this->validatedJob((array) $response->json('job'));
                $this->process($request, $url, $job);
                $processed++;
            } catch (Throwable $exception) {
                $hadError = true;
                Log::error('El agente de impresión no pudo completar su ciclo.', [
                    'exception_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $this->warn('Ciclo con error: '.$exception->getMessage());
            }

            if ($this->option('once')) {
                return $hadError ? self::FAILURE : self::SUCCESS;
            }
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                return self::SUCCESS;
            }

            sleep($interval);
        }
    }

    /** @return array{0:string,1:PendingRequest} */
    private function client(): array
    {
        $url = rtrim(trim((string) config('thermal-printing.agent.url')), '/');
        $token = trim((string) config('thermal-printing.agent.token'));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('PRINT_AGENT_URL no es una URL válida.');
        }
        if (config('thermal-printing.agent.require_https') && $scheme !== 'https') {
            throw new RuntimeException('PRINT_AGENT_URL debe usar HTTPS.');
        }
        if (strlen($token) < 48) {
            throw new RuntimeException('PRINT_AGENT_TOKEN no está configurado o es demasiado corto.');
        }

        return [
            $url,
            Http::withToken($token)->acceptJson()
                ->timeout(max(5, (int) config('thermal-printing.agent.request_timeout_seconds', 20))),
        ];
    }

    /** @param array<string,mixed> $job
     * @return array{id:string,type:string,copies:int,payload:string,claim_token:string}
     */
    private function validatedJob(array $job): array
    {
        $id = (string) ($job['id'] ?? '');
        $type = (string) ($job['type'] ?? '');
        $copies = (int) ($job['copies'] ?? 0);
        $payload = base64_decode((string) ($job['payload_base64'] ?? ''), true);
        $claimToken = (string) ($job['claim_token'] ?? '');

        if (strlen($id) !== 26 || ! in_array($type, [PrinterPurpose::Kitchen->value, PrinterPurpose::CustomerTicket->value], true)
            || $copies < 1 || $copies > 5 || $payload === false || strlen($claimToken) !== 64) {
            throw new RuntimeException('El servidor devolvió un trabajo de impresión inválido.');
        }

        return [
            'id' => $id,
            'type' => $type,
            'copies' => $copies,
            'payload' => $payload,
            'claim_token' => $claimToken,
        ];
    }

    /** @param array{id:string,type:string,copies:int,payload:string,claim_token:string} $job */
    private function process(PendingRequest $request, string $url, array $job): void
    {
        $started = microtime(true);
        $alreadyPrinted = $this->wasPrintedLocally($job['id']);
        $printer = $job['type'] === PrinterPurpose::Kitchen->value
            ? trim((string) config('thermal-printing.agent.kitchen_printer'))
            : trim((string) config('thermal-printing.agent.customer_printer'));

        if ($printer === '') {
            $this->reportFailure($request, $url, $job, 'La impresora local no está configurada.');
            throw new RuntimeException('La impresora local no está configurada.');
        }

        try {
            if (! $alreadyPrinted) {
                $this->transport->send($printer, $job['payload'], $job['copies']);
                $this->rememberPrintedLocally($job['id']);
            }

            $request->post($url.'/api/print-agent/jobs/'.$job['id'].'/printed', [
                'claim_token' => $job['claim_token'],
            ])->throw();
        } catch (Throwable $exception) {
            if (! $this->wasPrintedLocally($job['id'])) {
                $this->reportFailure($request, $url, $job, $exception->getMessage());
            }

            throw $exception;
        }

        Log::info('Trabajo térmico completado por el agente.', [
            'job_id' => $job['id'],
            'type' => $job['type'],
            'printer' => $printer,
            'copies' => $job['copies'],
            'ack_only' => $alreadyPrinted,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        $this->line('Impreso '.$job['id'].' ('.$job['type'].').');
    }

    /** @param array{id:string,claim_token:string} $job */
    private function reportFailure(PendingRequest $request, string $url, array $job, string $message): void
    {
        try {
            $request->post($url.'/api/print-agent/jobs/'.$job['id'].'/failed', [
                'claim_token' => $job['claim_token'],
                'error' => mb_substr(trim($message) ?: 'Error local de impresión.', 0, 500),
            ])->throw();
        } catch (Throwable $reportException) {
            Log::warning('No se pudo reportar el fallo de impresión al servidor.', [
                'job_id' => $job['id'],
                'message' => $reportException->getMessage(),
            ]);
        }
    }

    private function wasPrintedLocally(string $jobId): bool
    {
        return in_array($jobId, $this->localState(), true);
    }

    /** @return list<string> */
    private function localState(): array
    {
        $path = (string) config('thermal-printing.agent.state_file');
        if (! is_file($path)) {
            return [];
        }

        try {
            $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter((array) ($state['printed_job_ids'] ?? []), 'is_string'));
    }

    private function rememberPrintedLocally(string $jobId): void
    {
        $path = (string) config('thermal-printing.agent.state_file');
        File::ensureDirectoryExists(dirname($path));
        $ids = array_values(array_unique([...$this->localState(), $jobId]));
        $json = json_encode(['printed_job_ids' => array_slice($ids, -500)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el estado local del agente.');
        }
    }
}
