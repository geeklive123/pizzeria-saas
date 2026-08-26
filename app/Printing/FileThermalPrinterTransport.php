<?php

namespace App\Printing;

use App\Printing\Contracts\ThermalPrinterTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class FileThermalPrinterTransport implements ThermalPrinterTransport
{
    public function send(string $printerName, string $document, int $copies): void
    {
        $directory = (string) config('thermal-printing.agent.output_directory');
        File::ensureDirectoryExists($directory);
        $safePrinter = Str::slug($printerName) ?: 'thermal-printer';

        for ($copy = 1; $copy <= $copies; $copy++) {
            $path = $directory.DIRECTORY_SEPARATOR.now()->format('Ymd-His-v').'-'.$safePrinter.'-'.Str::lower(Str::random(6)).'.bin';
            if (file_put_contents($path, $document) === false) {
                throw new RuntimeException('No se pudo guardar la salida simulada de impresión.');
            }
        }
    }
}
