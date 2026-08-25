<?php

namespace App\Printing\Contracts;

interface ThermalPrinterTransport
{
    public function send(string $printerName, string $document, int $copies): void;
}
