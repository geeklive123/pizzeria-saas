<?php

namespace App\Actions;

use App\Enums\CashSessionStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\InventoryMovement;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CancelSettledKitchenDispatchAction
{
    public function __construct(
        private readonly ReversePaymentAction $reversePayment,
        private readonly ReverseInventoryMovementAction $reverseInventory,
        private readonly ReleaseInventoryReservationAction $releaseReservation,
        private readonly OrderTotalsService $totals,
        private readonly OrderPaymentService $payments,
    ) {}

    public function execute(KitchenDispatch $dispatch, User $user, string $reason): KitchenDispatch
    {
        Gate::forUser($user)->authorize('cancelPaid', $dispatch->order);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new DomainException('Indica un motivo de reversión de hasta 500 caracteres.');
        }

        $paymentSessionIds = Payment::query()->where('kitchen_dispatch_id', $dispatch->getKey())
            ->where('status', PaymentStatus::Completed->value)->pluck('cash_session_id');
        $registerIds = CashSession::query()->whereIn('id', $paymentSessionIds)
            ->pluck('cash_register_id')->unique();

        return DB::transaction(function () use ($dispatch, $user, $reason, $registerIds): KitchenDispatch {
            $sessions = CashSession::query()
                ->where('company_id', $dispatch->company_id)
                ->where('branch_id', $dispatch->branch_id)
                ->whereIn('cash_register_id', $registerIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $order = Order::query()->lockForUpdate()->findOrFail($dispatch->order_id);
            $dispatch = KitchenDispatch::query()->with('order')->lockForUpdate()->findOrFail($dispatch->getKey());
            $this->validateContext($order, $dispatch);

            $itemIds = $dispatch->items()->orderBy('order_item_id')->pluck('order_item_id');
            $items = OrderItem::query()->with(['company', 'reservations'])
                ->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
            $activeItems = $items->where('status', '!=', OrderItemStatus::Cancelled);
            if ($activeItems->isEmpty()) {
                throw new DomainException('Esta tanda no tiene productos activos para revertir.');
            }

            $payments = Payment::query()->with(['company', 'order'])
                ->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)
                ->where('order_id', $order->getKey())
                ->where('kitchen_dispatch_id', $dispatch->getKey())
                ->where('status', PaymentStatus::Completed->value)
                ->orderBy('id')->lockForUpdate()->get();
            if ($payments->isEmpty()) {
                throw new DomainException('La tanda pagada no tiene pagos completados para revertir.');
            }
            $paid = $payments->reduce(
                fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($payment->amount),
                BigDecimal::zero(),
            );
            if (! $paid->isEqualTo(BigDecimal::of($dispatch->total))) {
                throw new DomainException('Los pagos completados no coinciden exactamente con el total de la tanda.');
            }
            if ($this->payments->paid($order) !== $order->total) {
                throw new DomainException('El pedido pagado presenta una inconsistencia financiera previa.');
            }

            CashMovement::query()->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)
                ->where('reference_type', Payment::class)
                ->whereIn('reference_id', $payments->pluck('id'))
                ->orderBy('id')->lockForUpdate()->get();

            $cancelledAt = now();
            $beforeTotal = $order->total;
            $audit = OrderCancellationAudit::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'kitchen_dispatch_id' => $dispatch->getKey(),
                'scope' => OrderCancellationScope::KitchenDispatch,
                'snapshot' => [
                    'reversal_type' => 'settled_dispatch',
                    'previous_status' => $dispatch->status->value,
                    'dispatch_total' => $dispatch->total,
                    'order_total_before' => $beforeTotal,
                    'payments' => [],
                    'items' => [],
                ],
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ]);

            $paymentSnapshots = [];
            foreach ($payments as $payment) {
                $reversalSession = $this->openReversalSession($sessions, $payment);
                $originalCashMovements = CashMovement::query()
                    ->where('reference_type', Payment::class)
                    ->where('reference_id', $payment->getKey())
                    ->orderBy('id')->pluck('id')->all();
                $reversal = $this->reversePayment->execute(
                    $payment,
                    $reason,
                    $user,
                    forPaidCancellation: true,
                    reversalSession: $reversalSession,
                );
                $reversalCashMovements = CashMovement::query()
                    ->where('reference_type', Payment::class)
                    ->where('reference_id', $reversal->getKey())
                    ->orderBy('id')->pluck('id')->all();
                $paymentSnapshots[] = [
                    'original_payment_id' => $payment->getKey(),
                    'reversal_payment_id' => $reversal->getKey(),
                    'method' => $payment->method->value,
                    'amount' => $payment->amount,
                    'original_cash_session_id' => $payment->cash_session_id,
                    'reversal_cash_session_id' => $reversal->cash_session_id,
                    'original_cash_movement_ids' => $originalCashMovements,
                    'reversal_cash_movement_ids' => $reversalCashMovements,
                ];
            }

            $activeItemIds = $activeItems->pluck('id');
            $alreadyReversed = InventoryMovement::query()
                ->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)
                ->where('reference_type', OrderItem::class)
                ->whereIn('reference_id', $activeItemIds)
                ->where('type', InventoryMovementType::OrderConsumption->value)
                ->whereHas('reversals')->lockForUpdate()->exists();
            if ($alreadyReversed) {
                throw new DomainException('El inventario de la tanda ya contiene consumos revertidos.');
            }
            $movements = InventoryMovement::query()
                ->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)
                ->where('reference_type', OrderItem::class)
                ->whereIn('reference_id', $activeItemIds)
                ->where('type', InventoryMovementType::OrderConsumption->value)
                ->orderBy('inventory_item_id')->orderBy('id')->lockForUpdate()->get();

            $itemSnapshots = [];
            foreach ($activeItems as $item) {
                $reserved = $item->reservations
                    ->where('status', InventoryReservationStatus::Reserved);
                $reservationSnapshots = $reserved->map(fn ($reservation): array => [
                    'id' => $reservation->getKey(),
                    'inventory_item_id' => $reservation->inventory_item_id,
                    'quantity' => $reservation->quantity,
                ])->values()->all();
                $childAudit = OrderCancellationAudit::query()->create([
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->getKey(),
                    'kitchen_dispatch_id' => $dispatch->getKey(),
                    'order_item_id' => $item->getKey(),
                    'parent_id' => $audit->getKey(),
                    'scope' => OrderCancellationScope::KitchenDispatchItem,
                    'snapshot' => [
                        'previous_status' => $item->status->value,
                        'reservations' => $reservationSnapshots,
                        'inventory_reversals' => [],
                        'settled_dispatch_reversal' => true,
                    ],
                    'cancelled_at' => $cancelledAt,
                    'cancelled_by' => $user->getKey(),
                    'cancellation_reason' => $reason,
                ]);
                if ($reserved->isNotEmpty()) {
                    $this->releaseReservation->execute($item, $item->quantity);
                }
                $inventoryReversals = [];
                foreach ($movements->where('reference_id', $item->getKey()) as $movement) {
                    $reversal = $this->reverseInventory->execute(
                        $movement,
                        $user,
                        $reason,
                        requiredPermission: Permission::CancelOrders,
                    );
                    $inventoryReversals[] = [
                        'original_movement_id' => $movement->getKey(),
                        'reversal_movement_id' => $reversal->getKey(),
                    ];
                }
                $childAudit->forceFill(['snapshot' => [
                    ...$childAudit->snapshot,
                    'inventory_reversals' => $inventoryReversals,
                ]])->save();
                $item->forceFill([
                    'status' => OrderItemStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancelled_by' => $user->getKey(),
                    'cancellation_reason' => $reason,
                ])->save();
                $itemSnapshots[] = [
                    'order_item_id' => $item->getKey(),
                    'audit_id' => $childAudit->getKey(),
                    'previous_status' => $childAudit->snapshot['previous_status'],
                    'inventory_reversals' => $inventoryReversals,
                ];
            }

            $dispatch->forceFill([
                'status' => KitchenDispatchStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ])->save();
            $order = $this->totals->recalculate($order);
            if ($this->payments->balance($order) !== '0.00'
                || $this->payments->paid($order) !== $order->total) {
                throw new DomainException('La reversión no dejó el pedido restante completamente pagado.');
            }
            $order->forceFill(['status' => OrderStatus::Paid])->save();

            $audit->forceFill(['snapshot' => [
                ...$audit->snapshot,
                'payments' => $paymentSnapshots,
                'items' => $itemSnapshots,
                'order_total_after' => $order->total,
                'valid_paid_after' => $this->payments->paid($order),
                'valid_balance_after' => $this->payments->balance($order),
            ]])->save();

            return $dispatch->refresh();
        }, attempts: 3);
    }

    private function validateContext(Order $order, KitchenDispatch $dispatch): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new DomainException('El pedido está anulado.');
        }
        if ($order->status !== OrderStatus::Paid || $dispatch->status !== KitchenDispatchStatus::Settled) {
            throw new DomainException('Solo se puede revertir una tanda pagada y liquidada.');
        }
        if ((int) $dispatch->order_id !== (int) $order->getKey()
            || (int) $dispatch->company_id !== (int) $order->company_id
            || (int) $dispatch->branch_id !== (int) $order->branch_id) {
            throw new DomainException('La tanda no pertenece al pedido, empresa y sucursal indicados.');
        }
        if ($dispatch->cancellationAudits()
            ->where('scope', OrderCancellationScope::KitchenDispatch->value)
            ->where('snapshot->reversal_type', 'settled_dispatch')->exists()) {
            throw new DomainException('Esta tanda pagada ya fue revertida.');
        }
    }

    /** @param Collection<int, CashSession> $sessions */
    private function openReversalSession(Collection $sessions, Payment $payment): CashSession
    {
        $session = $sessions->get($payment->cash_session_id);
        if (! $session) {
            throw new DomainException('El pago no tiene una sesión de caja válida.');
        }

        if ($payment->method !== PaymentMethod::Cash) {
            return $session;
        }

        $visited = [];
        while ($session->status !== CashSessionStatus::Open) {
            if (isset($visited[$session->getKey()])) {
                throw new DomainException('La cadena de sesiones de caja contiene un ciclo.');
            }
            $visited[$session->getKey()] = true;
            $successors = $sessions->filter(
                fn (CashSession $candidate): bool => (int) $candidate->previous_cash_session_id === (int) $session->getKey(),
            );
            if ($successors->count() !== 1) {
                throw new DomainException('La caja original está cerrada y no existe un único turno sucesor trazable para registrar la devolución.');
            }
            $session = $successors->sole();
        }

        return $session;
    }
}
