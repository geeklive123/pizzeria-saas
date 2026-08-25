<?php

namespace App\Enums;

enum ExpensePaymentMethod: string
{
    case Cash = 'cash';
    case Qr = 'qr';
    case Transfer = 'transfer';
    case Other = 'other';
}
