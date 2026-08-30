<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\KitchenDispatch;
use App\Models\Payment;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReversePaymentAction
{
    public function __construct(private readonly RecordCashMovementAction $movements, private readonly CompanyAccessService $access) {}

    public function execute(Payment $payment, string $reason, User $user): Payment
    {
        $this->access->ensure($user, $payment->company, Permission::ReversePayments);
        if (blank($reason)) {
            throw new DomainException('Indica el motivo de la reversión.');
        }

        return DB::transaction(function () use ($payment, $reason, $user): Payment {
            $session = CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
            $payment = Payment::query()->with('order')->lockForUpdate()->findOrFail($payment->id);
            if ($session->status !== CashSessionStatus::Open) {
                throw new DomainException('No se puede revertir el pago porque el turno de caja ya está cerrado.');
            }
            if ($payment->order->status === OrderStatus::Paid) {
                throw new DomainException('Un pedido finalizado requiere el flujo de devolución correspondiente.');
            }
            if ($payment->status !== PaymentStatus::Completed || $payment->reversal_of_id !== null || $payment->reversals()->exists()) {
                throw new DomainException('Este pago ya fue revertido.');
            }

            $reversal = Payment::query()->create([
                'company_id' => $payment->company_id,
                'branch_id' => $payment->branch_id,
                'order_id' => $payment->order_id,
                'kitchen_dispatch_id' => $payment->kitchen_dispatch_id,
                'cash_session_id' => $payment->cash_session_id,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'reference' => $reason,
                'paid_at' => now(),
                'received_by' => $user->id,
                'status' => PaymentStatus::Reversed,
                'reversal_of_id' => $payment->id,
                'idempotency_key' => 'reverse-'.$payment->ulid,
            ]);
            $payment->forceFill(['status' => PaymentStatus::Reversed])->save();
            if ($payment->kitchen_dispatch_id) {
                KitchenDispatch::query()->whereKey($payment->kitchen_dispatch_id)->update([
                    'status' => KitchenDispatchStatus::Released->value,
                    'settled_at' => null,
                ]);
            }

            if ($payment->method === PaymentMethod::Cash) {
                $cashMovement = CashMovement::query()
                    ->where('cash_session_id', $session->id)
                    ->where('reference_type', Payment::class)
                    ->where('reference_id', $payment->id)
                    ->where('type', CashMovementType::SaleCash->value)
                    ->firstOrFail();
                $this->movements->execute($session, CashMovementType::Reversal, $payment->amount, $user, $reason, $reversal, $cashMovement);
            }

            return $reversal->refresh();
        });
    }
}
