<?php

namespace Tests\Feature;

use App\Printing\Contracts\ThermalPrinterTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintAgentWorkerTest extends TestCase
{
    public function test_worker_prints_remote_payload_and_acknowledges_it(): void
    {
        $transport = new WorkerRecordingTransport;
        $this->app->instance(ThermalPrinterTransport::class, $transport);
        $state = storage_path('framework/testing/worker-state-'.Str::random(8).'.json');
        $this->configureAgent($state);
        $jobId = (string) Str::ulid();
        $claimToken = Str::random(64);

        Http::fake([
            'https://prints.example/api/print-agent/jobs/claim' => Http::response(['job' => [
                'id' => $jobId,
                'type' => 'kitchen',
                'copies' => 2,
                'payload_base64' => base64_encode('ESC/POS'),
                'claim_token' => $claimToken,
            ]]),
            'https://prints.example/api/print-agent/jobs/'.$jobId.'/printed' => Http::response(['job' => ['id' => $jobId, 'status' => 'printed']]),
        ]);

        $this->assertSame(0, Artisan::call('print-agent:work', ['--once' => true]));
        $this->assertSame([['Cocina local', 'ESC/POS', 2]], $transport->documents);
        Http::assertSentCount(2);
        File::delete($state);
    }

    public function test_worker_does_not_print_again_when_ack_was_lost(): void
    {
        $transport = new WorkerRecordingTransport;
        $this->app->instance(ThermalPrinterTransport::class, $transport);
        $state = storage_path('framework/testing/worker-state-'.Str::random(8).'.json');
        $this->configureAgent($state);
        $jobId = (string) Str::ulid();

        $acknowledgements = 0;
        Http::fake(function ($request) use ($jobId, &$acknowledgements) {
            if (str_ends_with($request->url(), '/claim')) {
                return Http::response(['job' => $this->job($jobId, Str::random(64))]);
            }

            $acknowledgements++;

            return $acknowledgements === 1
                ? Http::response(['message' => 'timeout'], 500)
                : Http::response(['job' => ['id' => $jobId, 'status' => 'printed']]);
        });
        $this->assertSame(1, Artisan::call('print-agent:work', ['--once' => true]));
        $this->assertCount(1, $transport->documents);

        $this->assertSame(0, Artisan::call('print-agent:work', ['--once' => true]));
        $this->assertCount(1, $transport->documents);
        File::delete($state);
    }

    private function configureAgent(string $state): void
    {
        config()->set([
            'thermal-printing.agent.url' => 'https://prints.example',
            'thermal-printing.agent.token' => Str::random(80),
            'thermal-printing.agent.transport' => 'file',
            'thermal-printing.agent.kitchen_printer' => 'Cocina local',
            'thermal-printing.agent.customer_printer' => 'Caja local',
            'thermal-printing.agent.state_file' => $state,
            'thermal-printing.agent.require_https' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function job(string $id, string $claimToken): array
    {
        return [
            'id' => $id,
            'type' => 'customer_ticket',
            'copies' => 1,
            'payload_base64' => base64_encode('TICKET'),
            'claim_token' => $claimToken,
        ];
    }
}

class WorkerRecordingTransport implements ThermalPrinterTransport
{
    public array $documents = [];

    public function send(string $printerName, string $document, int $copies): void
    {
        $this->documents[] = [$printerName, $document, $copies];
    }
}
