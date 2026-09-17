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
use Illuminate\Support\Facades\Gate;

class ReversePaymentAction
{
    public function __construct(private readonly RecordCashMovementAction $movements, private readonly CompanyAccessService $access) {}

    public function execute(
        Payment $payment,
        string $reason,
        User $user,
        bool $forPaidCancellation = false,
        ?CashSession $reversalSession = null,
    ): Payment {
        $this->access->ensure($user, $payment->company, Permission::ReversePayments);
        if (blank($reason)) {
            throw new DomainException('Indica el motivo de la reversión.');
        }

        return DB::transaction(function () use ($payment, $reason, $user, $forPaidCancellation, $reversalSession): Payment {
            $sessionIds = collect([$payment->cash_session_id, $reversalSession?->getKey()])
                ->filter()->unique()->sort()->values();
            $sessions = CashSession::query()->whereIn('id', $sessionIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $payment = Payment::query()->with('order')->lockForUpdate()->findOrFail($payment->id);
            $sourceSession = $sessions->get($payment->cash_session_id);
            $session = $sessions->get($reversalSession?->getKey() ?? $payment->cash_session_id);
            if (! $sourceSession || ! $session
                || (int) $sourceSession->company_id !== (int) $payment->company_id
                || (int) $sourceSession->branch_id !== (int) $payment->branch_id
                || (int) $session->company_id !== (int) $payment->company_id
                || (int) $session->branch_id !== (int) $payment->branch_id) {
                throw new DomainException('La sesión usada para la reversión no corresponde al pago.');
            }
            if ($payment->method === PaymentMethod::Cash && $session->status !== CashSessionStatus::Open) {
                throw new DomainException('No se puede revertir el pago porque el turno de caja ya está cerrado.');
            }
            if ($forPaidCancellation) {
                Gate::forUser($user)->authorize('cancelPaid', $payment->order);
                if ($payment->order->status !== OrderStatus::Paid) {
                    throw new DomainException('Solo se pueden anular y revertir pedidos pagados.');
                }
            } elseif ($payment->order->status === OrderStatus::Paid) {
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
                'cash_session_id' => $session->id,
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
            if ($payment->kitchen_dispatch_id && ! $forPaidCancellation) {
                KitchenDispatch::query()->whereKey($payment->kitchen_dispatch_id)->update([
                    'status' => KitchenDispatchStatus::Released->value,
                    'settled_at' => null,
                ]);
            }

            if ($payment->method === PaymentMethod::Cash) {
                $cashMovement = CashMovement::query()
                    ->where('cash_session_id', $sourceSession->id)
                    ->where('reference_type', Payment::class)
                    ->where('reference_id', $payment->id)
                    ->whereIn('type', [CashMovementType::SaleCash->value, CashMovementType::AdministrativeTransferIn->value])
                    ->latest('id')
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->movements->execute($session, CashMovementType::Reversal, $payment->amount, $user, $reason, $reversal, $cashMovement);
            }

            return $reversal->refresh();
        });
    }
}
