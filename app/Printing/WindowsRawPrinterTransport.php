<?php

namespace App\Printing;

use App\Printing\Contracts\ThermalPrinterTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class WindowsRawPrinterTransport implements ThermalPrinterTransport
{
    public function send(string $printerName, string $document, int $copies): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('La impresión RAW está disponible únicamente en Windows.');
        }

        $script = (string) config('thermal-printing.raw_spooler_script');
        if (! is_file($script)) {
            throw new RuntimeException('No se encontró el adaptador del spooler de Windows.');
        }

        $temporaryDirectory = (string) config('thermal-printing.temporary_directory');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryFile = tempnam($temporaryDirectory, 'masa-mana-print-');
        if ($temporaryFile === false || file_put_contents($temporaryFile, $document) === false) {
            throw new RuntimeException('No se pudo preparar el documento de impresión.');
        }

        $process = null;
        try {
            $process = new Process([
                (string) config('thermal-printing.powershell'),
                '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', $script,
                '-PrinterName', $printerName,
                '-DocumentPath', $temporaryFile,
                '-Copies', (string) $copies,
            ]);
            $process->setTimeout((float) config('thermal-printing.timeout_seconds', 15));
            $process->run();
            $context = $this->processContext($process) + [
                'transport' => 'windows_raw_escpos',
                'printer_name' => $printerName,
                'copies' => $copies,
            ];

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            Log::info('El transporte RAW de Windows completó el envío.', $context);
        } catch (Throwable $exception) {
            Log::error('El transporte RAW de Windows falló.', $this->processContext($process) + [
                'transport' => 'windows_raw_escpos',
                'printer_name' => $printerName,
                'copies' => $copies,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'php_sapi' => PHP_SAPI,
                'process_id' => getmypid(),
                'working_directory' => getcwd(),
                'script_path' => $script,
                'temporary_directory' => $temporaryDirectory,
                'system_temporary_directory' => sys_get_temp_dir(),
                'system_temporary_directory_writable' => is_writable(sys_get_temp_dir()),
            ]);

            throw $exception;
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @return array{command:?string,started:bool,exit_code:?int,stdout:?string,stderr:?string} */
    private function processContext(?Process $process): array
    {
        $started = $process?->isStarted() ?? false;

        return [
            'command' => $process?->getCommandLine(),
            'started' => $started,
            'exit_code' => $started ? $process->getExitCode() : null,
            'stdout' => $started ? $process->getOutput() : null,
            'stderr' => $started ? $process->getErrorOutput() : null,
        ];
    }
}
