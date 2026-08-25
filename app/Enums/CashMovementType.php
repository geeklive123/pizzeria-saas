<?php

namespace App\Enums;

enum CashMovementType: string
{
    case Opening = 'opening';
    case SaleCash = 'sale_cash';
    case ManualIn = 'manual_in';
    case ManualOut = 'manual_out';
    case ExpenseOut = 'expense_out';
    case ExpenseReversal = 'expense_reversal';
    case OwnerWithdrawal = 'owner_withdrawal';
    case Reversal = 'reversal';

    public function direction(): int
    {
        return match ($this) {
            self::Opening, self::SaleCash, self::ManualIn, self::ExpenseReversal => 1,
            self::ManualOut, self::ExpenseOut, self::OwnerWithdrawal, self::Reversal => -1,
        };
    }
}
