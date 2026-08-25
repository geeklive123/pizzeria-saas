<?php

namespace App\Printing\Renderers;

use App\Printing\EscPosDocumentBuilder;
use App\Printing\ThermalDocument;
use DateTimeZone;

class TestPageRenderer
{
    public function render(string $printerName): ThermalDocument
    {
        $date = now()->setTimezone(new DateTimeZone('America/La_Paz'));

        return (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('MASA & MAÑA')->doubleSize(false)
            ->line('PRUEBA DE IMPRESIÓN')->bold(false)->line()
            ->alignLeft()->line('Impresora:')->line($printerName)->line()
            ->line('Fecha/Hora:')->line($date->format('d/m/Y H:i:s'))->line()
            ->alignCenter()->bold()->line('IMPRESIÓN CORRECTA')->bold(false)->finish();
    }
}
