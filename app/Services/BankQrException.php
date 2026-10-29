<?php

namespace App\Services;

use DomainException;

class BankQrException extends DomainException
{
    public function __construct(public readonly string $issue, public readonly bool $uncertain = false, public readonly ?string $operation = null, public readonly ?int $httpStatus = null, public readonly ?string $responseCode = null)
    {
        parent::__construct('Banco Económico no pudo completar la operación. Verifica el estado antes de reintentar.');
    }
}
