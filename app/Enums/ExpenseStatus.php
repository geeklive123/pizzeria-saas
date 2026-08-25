<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
}
