<?php

namespace App\Exceptions;

use DomainException;

class InsufficientPreparationStockException extends DomainException
{
    /** @param list<array{name:string,missing:string,unit:string}> $shortages */
    public function __construct(
        public readonly int $maximumLots,
        public readonly array $shortages,
        string $preparationName,
        int $requestedLots,
    ) {
        $details = collect($shortages)
            ->map(fn (array $shortage): string => $shortage['name'].': '.$shortage['missing'].' '.$shortage['unit'])
            ->join(', ');
        $message = 'No hay stock suficiente para producir '.$requestedLots.' lotes de '.$preparationName.'. '
            .'Máximo posible actualmente: '.$maximumLots.' lotes.';

        parent::__construct($details === '' ? $message : $message.' Faltante: '.$details.'.');
    }
}
