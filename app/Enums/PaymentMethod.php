<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qr = 'qr';
    case Card = 'card';
    case Transfer = 'transfer';
    case Other = 'other';
}
