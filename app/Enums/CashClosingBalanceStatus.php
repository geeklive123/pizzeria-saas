<?php

namespace App\Enums;

enum CashClosingBalanceStatus: string
{
    case Balanced = 'balanced';
    case Short = 'short';
    case Over = 'over';
}
