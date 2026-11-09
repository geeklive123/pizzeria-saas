<?php

namespace App\Exceptions;

use DomainException;

class FinancialVersionConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('El pedido cambió desde que se abrió la pantalla. Consulta el saldo actualizado antes de volver a cobrar.');
    }
}
