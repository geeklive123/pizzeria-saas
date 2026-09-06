<?php

namespace App\Services;

use App\Enums\CashClosingBalanceStatus;
use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashSession;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class CashSessionSummaryService
{
    public function persistDerivedSnapshot(CashSession $session): CashSession
    {
        $session = $session->fresh();
        $summary = $this->calculate($session);
        $expected = BigDecimal::of($summary['expected_cash'])->toScale(2, RoundingMode::HalfUp);
        $values = ['expected_cash_amount' => (string) $expected, 'updated_at' => now()];

        if ($session->counted_cash_amount !== null) {
            $difference = BigDecimal::of($session->counted_cash_amount)
                ->minus($expected)
                ->toScale(2, RoundingMode::HalfUp);
            $values['difference_amount'] = (string) $difference;
            $values['closing_balance_status'] = ($difference->isZero()
                ? CashClosingBalanceStatus::Balanced
                : ($difference->isNegative()
                    ? CashClosingBalanceStatus::Short
                    : CashClosingBalanceStatus::Over))->value;
        }

        // Closed sessions reject ordinary model updates. This narrow update changes only
        // their derived closing snapshot after an audited historical reassignment.
        DB::table('cash_sessions')->where('id', $session->id)->update($values);

        return $session->fresh();
    }

    /** @return array<int, string> */
    public function movementBalances(CashSession $session): array
    {
        $balance = BigDecimal::zero();
        $balances = [];
        $movements = $session->relationLoaded('movements')
            ? $session->movements->sortBy('occurred_at')
            : $session->movements()->orderBy('occurred_at')->get(['id', 'type', 'amount']);

        foreach ($movements as $movement) {
            $signed = BigDecimal::of($movement->amount)->multipliedBy($movement->type->direction());
            $balance = $balance->plus($signed);
            $balances[$movement->id] = (string) $balance->toScale(2, RoundingMode::HalfUp);
        }

        return $balances;
    }

    /** @return array{sales_total:string,cash_payments:string,qr_payments:string,orders_paid:int,manual_in:string,manual_out:string,expense_cash:string,owner_withdrawals:string,expected_cash:string} */
    public function calculate(CashSession $session): array
    {
        $payments = $session->relationLoaded('payments')
            ? $session->payments->where('status', PaymentStatus::Completed)
            : $session->payments()->where('status', PaymentStatus::Completed->value)->get(['order_id', 'method', 'amount']);
        $sales = BigDecimal::zero();
        $cash = BigDecimal::zero();
        $qr = BigDecimal::zero();
        foreach ($payments as $payment) {
            $amount = BigDecimal::of($payment->amount);
            $sales = $sales->plus($amount);
            if ($payment->method === PaymentMethod::Cash) {
                $cash = $cash->plus($amount);
            } elseif ($payment->method === PaymentMethod::Qr) {
                $qr = $qr->plus($amount);
            }
        }

        $expected = BigDecimal::zero();
        $manualIn = BigDecimal::zero();
        $manualOut = BigDecimal::zero();
        $expenseCash = BigDecimal::zero();
        $ownerWithdrawals = BigDecimal::zero();
        $movements = $session->relationLoaded('movements') ? $session->movements : $session->movements()->get(['type', 'amount']);
        foreach ($movements as $movement) {
            $amount = BigDecimal::of($movement->amount);
            $expected = $movement->type->direction() > 0 ? $expected->plus($amount) : $expected->minus($amount);
            if ($movement->type === CashMovementType::ManualIn) {
                $manualIn = $manualIn->plus($amount);
            } elseif ($movement->type === CashMovementType::ManualOut) {
                $manualOut = $manualOut->plus($amount);
            } elseif ($movement->type === CashMovementType::ExpenseOut) {
                $expenseCash = $expenseCash->plus($amount);
            } elseif ($movement->type === CashMovementType::OwnerWithdrawal) {
                $ownerWithdrawals = $ownerWithdrawals->plus($amount);
            }
        }

        $ordersPaid = $session->relationLoaded('payments') && $payments->every(fn ($payment) => $payment->relationLoaded('order'))
            ? $payments->filter(fn ($payment) => $payment->order?->status === OrderStatus::Paid)->pluck('order_id')->unique()->count()
            : Order::query()
                ->where('company_id', $session->company_id)
                ->where('branch_id', $session->branch_id)
                ->whereIn('id', $payments->pluck('order_id')->unique())
                ->where('status', OrderStatus::Paid->value)
                ->count();

        $format = fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HalfUp);

        return [
            'sales_total' => $format($sales),
            'cash_payments' => $format($cash),
            'qr_payments' => $format($qr),
            'orders_paid' => $ordersPaid,
            'manual_in' => $format($manualIn),
            'manual_out' => $format($manualOut),
            'expense_cash' => $format($expenseCash),
            'owner_withdrawals' => $format($ownerWithdrawals),
            'expected_cash' => $format($expected),
        ];
    }
}
