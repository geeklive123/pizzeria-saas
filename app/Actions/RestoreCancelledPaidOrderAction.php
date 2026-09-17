<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashSession;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreCancelledPaidOrderAction
{
    public function __construct(
        private readonly RestoreCancellationInventoryAction $restoreInventory,
        private readonly RecordCashMovementAction $cashMovements,
    ) {}

    public function execute(Order $order, User $user, string $reason): Order
    {
        Gate::forUser($user)->authorize('restoreCancellation', $order);
        if (blank($reason) || mb_strlen($reason) > 500) {
            throw new DomainException('Indica un motivo de restauración de hasta 500 caracteres.');
        }

        return DB::transaction(function () use ($order, $user, $reason): Order {
            $knownAudit = OrderCancellationAudit::query()
                ->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)
                ->where('order_id', $order->getKey())
                ->where('scope', OrderCancellationScope::PaidOrder->value)
                ->whereNull('parent_id')
                ->latest('id')->first();
            if (! $knownAudit) {
                throw new DomainException('Esta anulación no posee auditoría restaurable; puede corresponder a datos legacy.');
            }

            $paymentIds = collect($knownAudit->snapshot['payments'] ?? [])->pluck('original_payment_id');
            CashSession::query()
                ->whereIn('id', Payment::query()->whereIn('id', $paymentIds)->select('cash_session_id'))
                ->orderBy('id')->lockForUpdate()->get();

            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($order->status !== OrderStatus::Cancelled) {
                throw new DomainException('Este pedido no está anulado o ya fue restaurado.');
            }
            $audit = OrderCancellationAudit::query()->lockForUpdate()->findOrFail($knownAudit->getKey());
            if ($audit->restored_at !== null) {
                throw new DomainException('Esta anulación ya fue restaurada.');
            }

            $paymentSnapshots = collect($audit->snapshot['payments'] ?? []);
            if ($paymentSnapshots->isEmpty()) {
                throw new DomainException('La auditoría no contiene pagos restaurables.');
            }
            $originalPayments = Payment::query()->whereIn('id', $paymentSnapshots->pluck('original_payment_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $reversalPayments = Payment::query()->whereIn('id', $paymentSnapshots->pluck('reversal_payment_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sessions = CashSession::query()->whereIn('id', $originalPayments->pluck('cash_session_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $paymentTotal = BigDecimal::zero();
            foreach ($paymentSnapshots as $entry) {
                $original = $originalPayments->get($entry['original_payment_id']);
                $reversal = $reversalPayments->get($entry['reversal_payment_id']);
                if (! $original || ! $reversal
                    || $original->status !== PaymentStatus::Reversed
                    || $reversal->status !== PaymentStatus::Reversed
                    || (int) $reversal->reversal_of_id !== (int) $original->getKey()
                    || (int) $original->order_id !== (int) $order->getKey()) {
                    throw new DomainException('La evidencia de reversión de pagos es inconsistente.');
                }
                if ($sessions->get($original->cash_session_id)?->status !== CashSessionStatus::Open) {
                    throw new DomainException('No se puede restaurar la venta porque la caja original está cerrada.');
                }
                $paymentTotal = $paymentTotal->plus($original->amount);
            }
            if (! $paymentTotal->isEqualTo(BigDecimal::of($order->total))) {
                throw new DomainException('Los pagos auditados no coinciden con el total original del pedido.');
            }

            $children = $audit->children()->orderBy('order_item_id')->lockForUpdate()->get();
            if ($children->isEmpty()) {
                throw new DomainException('La auditoría no contiene productos restaurables.');
            }
            foreach ($children as $child) {
                $item = OrderItem::query()->with(['company', 'order.branch'])->lockForUpdate()->findOrFail($child->order_item_id);
                if ($item->status !== OrderItemStatus::Cancelled) {
                    throw new DomainException('Un producto auditado ya no está anulado.');
                }
                $createdMovements = $this->restoreInventory->execute($child, $item, $user);
                $previousStatus = OrderItemStatus::tryFrom((string) ($child->snapshot['previous_status'] ?? ''));
                if (! $previousStatus || $previousStatus === OrderItemStatus::Cancelled) {
                    throw new DomainException('La auditoría contiene un estado previo de producto inválido.');
                }
                $item->forceFill(['status' => $previousStatus])->save();
                $child->forceFill([
                    'snapshot' => [...$child->snapshot, 'restoration_inventory_movement_ids' => collect($createdMovements)->map->getKey()->all()],
                    'restored_at' => now(),
                    'restored_by' => $user->getKey(),
                    'restoration_reason' => $reason,
                ])->save();
            }

            foreach ($audit->snapshot['dispatches'] ?? [] as $dispatchSnapshot) {
                $dispatch = KitchenDispatch::query()->lockForUpdate()->findOrFail($dispatchSnapshot['id']);
                $status = KitchenDispatchStatus::tryFrom((string) ($dispatchSnapshot['previous_status'] ?? ''));
                if (! $status || $status === KitchenDispatchStatus::Cancelled) {
                    throw new DomainException('La auditoría contiene un estado previo de tanda inválido.');
                }
                $dispatch->forceFill(['status' => $status])->save();
            }

            $restoredPayments = [];
            $restoredCashMovements = [];
            foreach ($paymentSnapshots as $entry) {
                $original = $originalPayments[$entry['original_payment_id']];
                $compensation = Payment::query()->create([
                    'company_id' => $original->company_id,
                    'branch_id' => $original->branch_id,
                    'order_id' => $original->order_id,
                    'kitchen_dispatch_id' => $original->kitchen_dispatch_id,
                    'cash_session_id' => $original->cash_session_id,
                    'method' => $original->method,
                    'amount' => $original->amount,
                    'reference' => mb_substr('Restauración: '.$reason, 0, 190),
                    'paid_at' => now(),
                    'received_by' => $user->getKey(),
                    'status' => PaymentStatus::Completed,
                    'idempotency_key' => 'restore-'.$audit->ulid.'-'.$original->getKey(),
                ]);
                $restoredPayments[] = [
                    'original_payment_id' => $original->getKey(),
                    'reversal_payment_id' => $entry['reversal_payment_id'],
                    'compensation_payment_id' => $compensation->getKey(),
                ];
                if ($original->method === PaymentMethod::Cash) {
                    $movement = $this->cashMovements->execute(
                        $sessions[$original->cash_session_id],
                        CashMovementType::SaleCash,
                        $original->amount,
                        $user,
                        $reason,
                        $compensation,
                        idempotencyKey: 'restore-'.$audit->ulid.'-'.$original->getKey(),
                    );
                    $restoredCashMovements[] = $movement->getKey();
                }
            }

            $order->forceFill(['status' => OrderStatus::Paid])->save();
            $audit->forceFill([
                'snapshot' => [
                    ...$audit->snapshot,
                    'restoration_payments' => $restoredPayments,
                    'restoration_cash_movement_ids' => $restoredCashMovements,
                ],
                'restored_at' => now(),
                'restored_by' => $user->getKey(),
                'restoration_reason' => $reason,
            ])->save();

            return $order->refresh();
        }, attempts: 3);
    }
}
