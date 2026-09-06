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
    case AdministrativeTransferIn = 'admin_transfer_in';
    case AdministrativeTransferOut = 'admin_transfer_out';

    public function direction(): int
    {
        return match ($this) {
            self::Opening, self::SaleCash, self::ManualIn, self::ExpenseReversal, self::AdministrativeTransferIn => 1,
            self::ManualOut, self::ExpenseOut, self::OwnerWithdrawal, self::Reversal, self::AdministrativeTransferOut => -1,
        };
    }
}
