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
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CashSessionTransfer;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreLegacyCancelledPaidOrderAction
{
    public const RESTORATION_REASON = 'RECUPERACIÓN ADMINISTRATIVA DE ANULACIÓN LEGACY';

    public function __construct(
        private readonly ApplyInventoryMovementAction $applyInventoryMovement,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    /** @return array<string, mixed> */
    public function dryRun(Order $order, User $user, array $expectations = []): array
    {
        Gate::forUser($user)->authorize('restoreCancellation', $order);

        return DB::transaction(function () use ($order, $expectations): array {
            $report = $this->report($this->evidence($order->getKey(), false));
            $this->assertExpectations($report, $expectations);

            return $report;
        });
    }

    public function execute(Order $order, User $user, array $expectations = []): OrderCancellationAudit
    {
        Gate::forUser($user)->authorize('restoreCancellation', $order);

        return DB::transaction(function () use ($order, $user, $expectations): OrderCancellationAudit {
            $evidence = $this->evidence($order->getKey(), true);
            $this->assertExpectations($this->report($evidence), $expectations);
            $now = now();
            /** @var Order $lockedOrder */
            $lockedOrder = $evidence['order'];

            $parent = OrderCancellationAudit::query()->create([
                'company_id' => $lockedOrder->company_id,
                'branch_id' => $lockedOrder->branch_id,
                'order_id' => $lockedOrder->getKey(),
                'scope' => OrderCancellationScope::PaidOrder,
                'snapshot' => [
                    ...$this->report($evidence),
                    'recovery_type' => 'legacy_administrative',
                    'no_original_paid_order_audit' => true,
                    'execution_started_at' => $now->toIso8601String(),
                    'payments' => collect($evidence['payment_pairs'])->map(fn (array $pair): array => [
                        'original_payment_id' => $pair['original']->getKey(),
                        'reversal_payment_id' => $pair['reversal']->getKey(),
                    ])->all(),
                    'dispatches' => collect($evidence['dispatches'])->map(fn (KitchenDispatch $dispatch): array => [
                        'id' => $dispatch->getKey(),
                        'previous_status' => KitchenDispatchStatus::Settled->value,
                    ])->all(),
                ],
                'cancelled_at' => $lockedOrder->cancelled_at,
                'cancelled_by' => $lockedOrder->cancelled_by,
                'cancellation_reason' => $lockedOrder->cancellation_reason,
                'restored_at' => $now,
                'restored_by' => $user->getKey(),
                'restoration_reason' => self::RESTORATION_REASON,
            ]);

            $createdInventoryMovements = [];
            foreach ($evidence['items'] as $itemEvidence) {
                /** @var OrderItem $item */
                $item = $itemEvidence['item'];
                $child = OrderCancellationAudit::query()->create([
                    'company_id' => $item->company_id,
                    'branch_id' => $item->branch_id,
                    'order_id' => $item->order_id,
                    'kitchen_dispatch_id' => $itemEvidence['dispatch']->getKey(),
                    'order_item_id' => $item->getKey(),
                    'parent_id' => $parent->getKey(),
                    'scope' => OrderCancellationScope::KitchenDispatchItem,
                    'snapshot' => [
                        'recovery_type' => 'legacy_administrative',
                        'no_original_paid_order_audit' => true,
                        'previous_status' => $itemEvidence['previous_status']->value,
                        'reservations' => [],
                        'preserved_consumed_reservation_ids' => $itemEvidence['reservations']->pluck('id')->all(),
                        'inventory_reversals' => collect($itemEvidence['movement_pairs'])->map(fn (array $pair): array => [
                            'original_movement_id' => $pair['original']->getKey(),
                            'reversal_movement_id' => $pair['reversal']->getKey(),
                        ])->all(),
                    ],
                    'cancelled_at' => $item->cancelled_at,
                    'cancelled_by' => $item->cancelled_by,
                    'cancellation_reason' => $item->cancellation_reason,
                    'restored_at' => $now,
                    'restored_by' => $user->getKey(),
                    'restoration_reason' => self::RESTORATION_REASON,
                ]);

                $itemMovementIds = [];
                foreach ($itemEvidence['movement_pairs'] as $pair) {
                    /** @var InventoryMovement $original */
                    $original = $pair['original'];
                    /** @var InventoryMovement $reversal */
                    $reversal = $pair['reversal'];
                    $movement = $this->applyInventoryMovement->execute(
                        $item->company,
                        $lockedOrder->branch,
                        $original->inventoryItem,
                        InventoryMovementType::OrderConsumption,
                        $original->quantity,
                        $original->unit_cost,
                        $user,
                        reason: self::RESTORATION_REASON,
                        occurredAt: $now,
                        referenceType: OrderItem::class,
                        referenceId: $item->getKey(),
                        metadata: [
                            'legacy_administrative_restore' => true,
                            'audit_id' => $child->getKey(),
                            'original_movement_id' => $original->getKey(),
                            'reversal_movement_id' => $reversal->getKey(),
                            'order_id' => $lockedOrder->getKey(),
                            'order_item_id' => $item->getKey(),
                            'reason' => self::RESTORATION_REASON,
                        ],
                    );
                    $itemMovementIds[] = $movement->getKey();
                    $createdInventoryMovements[] = $movement->getKey();
                }

                $child->forceFill([
                    'snapshot' => [
                        ...$child->snapshot,
                        'restoration_inventory_movement_ids' => $itemMovementIds,
                    ],
                    'cancelled_at' => $item->cancelled_at,
                ])->save();
                $item->forceFill(['status' => $itemEvidence['previous_status']])->save();
            }

            $compensatingPayments = [];
            foreach ($evidence['payment_pairs'] as $pair) {
                /** @var Payment $original */
                $original = $pair['original'];
                $compensation = Payment::query()->create([
                    'company_id' => $original->company_id,
                    'branch_id' => $original->branch_id,
                    'order_id' => $original->order_id,
                    'kitchen_dispatch_id' => $original->kitchen_dispatch_id,
                    'cash_session_id' => $original->cash_session_id,
                    'method' => PaymentMethod::Qr,
                    'amount' => $original->amount,
                    'reference' => self::RESTORATION_REASON,
                    'paid_at' => $original->paid_at,
                    'received_by' => $user->getKey(),
                    'status' => PaymentStatus::Completed,
                    'idempotency_key' => $this->paymentIdempotencyKey($lockedOrder, $original),
                ]);
                $compensatingPayments[] = [
                    'original_payment_id' => $original->getKey(),
                    'reversal_payment_id' => $pair['reversal']->getKey(),
                    'compensation_payment_id' => $compensation->getKey(),
                    'paid_at_uses_original_economic_date' => true,
                    'created_at_is_administrative_recovery_time' => true,
                ];
            }

            foreach ($evidence['dispatches'] as $dispatch) {
                $dispatch->forceFill(['status' => KitchenDispatchStatus::Settled])->save();
            }
            $lockedOrder->forceFill(['status' => OrderStatus::Paid])->save();

            $parent->forceFill([
                'snapshot' => [
                    ...$parent->snapshot,
                    'compensations' => [
                        'payments' => $compensatingPayments,
                        'inventory_movement_ids' => $createdInventoryMovements,
                        'cash_movement_ids' => [],
                    ],
                    'executed_at' => $now->toIso8601String(),
                ],
                'cancelled_at' => $lockedOrder->cancelled_at,
            ])->save();

            return $parent->refresh()->load('children');
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function evidence(int $orderId, bool $lock): array
    {
        $orderQuery = Order::query()->with(['company', 'branch'])->whereKey($orderId);
        /** @var Order $order */
        $order = $this->locked($orderQuery, $lock)->firstOrFail();

        if ($order->status !== OrderStatus::Cancelled || $order->cancelled_at === null
            || $order->cancelled_by === null || blank($order->cancellation_reason)) {
            throw new DomainException('La venta legacy no conserva un estado de anulación completo.');
        }
        if ($order->active_restaurant_table_id !== null) {
            throw new DomainException('La venta anulada todavía ocupa una mesa y no puede recuperarse automáticamente.');
        }

        $auditQuery = OrderCancellationAudit::query()
            ->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)
            ->where('order_id', $order->getKey())
            ->where('scope', OrderCancellationScope::PaidOrder->value)
            ->whereNull('parent_id');
        if ($this->locked($auditQuery, $lock)->exists()) {
            throw new DomainException('La venta ya posee una auditoría PaidOrder y no corresponde al flujo legacy.');
        }

        $paymentQuery = Payment::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->where('order_id', $order->getKey())->orderBy('id');
        /** @var Collection<int, Payment> $payments */
        $payments = $this->locked($paymentQuery, $lock)->get();
        if ($payments->isEmpty() || $payments->contains(fn (Payment $payment): bool => $payment->status === PaymentStatus::Completed)) {
            throw new DomainException('La evidencia de pagos legacy no está completamente revertida.');
        }

        $paymentPairs = [];
        $paymentTotal = BigDecimal::zero();
        $originals = $payments->whereNull('reversal_of_id')->values();
        foreach ($originals as $original) {
            $reversals = $payments->where('reversal_of_id', $original->getKey())->values();
            if ($original->status !== PaymentStatus::Reversed || $original->method !== PaymentMethod::Qr
                || $reversals->count() !== 1) {
                throw new DomainException('Solo se admite evidencia QR con una reversión inequívoca por pago original.');
            }
            $reversal = $reversals->first();
            if ($reversal->status !== PaymentStatus::Reversed || $reversal->method !== $original->method
                || $reversal->amount !== $original->amount || $reversal->cash_session_id !== $original->cash_session_id
                || $reversal->kitchen_dispatch_id !== $original->kitchen_dispatch_id
                || $reversal->idempotency_key !== 'reverse-'.$original->ulid) {
                throw new DomainException('El pago original y su reversión no coinciden exactamente.');
            }
            if ($payments->contains(fn (Payment $payment): bool => (int) $payment->reversal_of_id === (int) $reversal->getKey())) {
                throw new DomainException('La cadena de reversión de pagos contiene niveles inesperados.');
            }
            $idempotencyKey = $this->paymentIdempotencyKey($order, $original);
            if (Payment::query()->where('company_id', $order->company_id)->where('idempotency_key', $idempotencyKey)->exists()) {
                throw new DomainException('Ya existe un pago compensatorio para esta recuperación legacy.');
            }
            $paymentTotal = $paymentTotal->plus($original->amount);
            $paymentPairs[] = compact('original', 'reversal');
        }
        if ($originals->isEmpty() || count($paymentPairs) * 2 !== $payments->count()
            || ! $paymentTotal->isEqualTo(BigDecimal::of($order->total))) {
            throw new DomainException('Los pagos legacy no reconcilian exactamente con el total de la venta.');
        }

        $sessionIds = $originals->pluck('cash_session_id')->unique()->sort()->values();
        $sessionQuery = CashSession::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->whereIn('id', $sessionIds)->orderBy('id');
        $sessions = $this->locked($sessionQuery, $lock)->get()->keyBy('id');
        foreach ($originals as $original) {
            if ($sessions->get($original->cash_session_id)?->status !== CashSessionStatus::Open) {
                throw new DomainException('La sesión administrativa vigente del pago QR no está abierta.');
            }
        }

        $paymentIds = $payments->pluck('id');
        if (CashMovement::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)
            ->where('reference_type', Payment::class)->whereIn('reference_id', $paymentIds)->exists()) {
            throw new DomainException('Un pago QR legacy posee movimientos físicos de caja inesperados.');
        }

        $transfers = CashSessionTransfer::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->where('order_id', $order->getKey())->orderBy('id')->get()
            ->filter(fn (CashSessionTransfer $transfer): bool => collect($transfer->payment_ids)->intersect($originals->pluck('id'))->isNotEmpty())
            ->values();
        foreach ($transfers as $transfer) {
            foreach (collect($transfer->payment_ids)->intersect($originals->pluck('id')) as $paymentId) {
                $payment = $originals->firstWhere('id', $paymentId);
                if (! $payment || (int) $payment->cash_session_id !== (int) $transfer->destination_cash_session_id) {
                    throw new DomainException('La transferencia administrativa del pago no coincide con su sesión vigente.');
                }
            }
        }

        $dispatchIds = $originals->pluck('kitchen_dispatch_id')->filter()->unique()->sort()->values();
        if ($dispatchIds->count() !== $originals->pluck('kitchen_dispatch_id')->unique()->count()) {
            throw new DomainException('Todos los pagos legacy deben pertenecer a una tanda identificable.');
        }
        $dispatchQuery = KitchenDispatch::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->where('order_id', $order->getKey())
            ->whereIn('id', $dispatchIds)->orderBy('id');
        /** @var Collection<int, KitchenDispatch> $dispatches */
        $dispatches = $this->locked($dispatchQuery, $lock)->get();
        if ($dispatches->count() !== $dispatchIds->count()) {
            throw new DomainException('Falta una tanda referenciada por los pagos legacy.');
        }
        foreach ($dispatches as $dispatch) {
            $dispatchPaymentTotal = $originals->where('kitchen_dispatch_id', $dispatch->getKey())
                ->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($payment->amount), BigDecimal::zero());
            if ($dispatch->status !== KitchenDispatchStatus::Cancelled || $dispatch->settled_at === null
                || ! $dispatchPaymentTotal->isEqualTo(BigDecimal::of($dispatch->total))) {
                throw new DomainException('La tanda legacy no conserva evidencia suficiente de su estado Settled previo.');
            }
        }

        $itemQuery = OrderItem::query()->with(['company', 'reservations', 'kitchenDispatchItem.dispatch'])
            ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('order_id', $order->getKey())->orderBy('id');
        $allItems = $this->locked($itemQuery, $lock)->get();
        $items = [];
        $requirements = [];
        $batchIds = [];
        $movementIds = [];

        foreach ($allItems as $item) {
            if (! $this->wasCancelledWithOrder($item, $order)) {
                continue;
            }
            $dispatch = $item->kitchenDispatchItem?->dispatch;
            if ($item->status !== OrderItemStatus::Cancelled || ! $dispatch || ! $dispatchIds->contains($dispatch->getKey())) {
                throw new DomainException('Un producto legacy no coincide con las tandas pagadas auditadas.');
            }
            $previousStatus = $this->previousItemStatus($item);
            $reservations = $item->reservations;
            if ($reservations->contains(fn (InventoryReservation $reservation): bool => $reservation->status !== InventoryReservationStatus::Consumed)) {
                throw new DomainException('Las reservas de un producto legacy no permanecen consumidas.');
            }

            $movementQuery = InventoryMovement::query()->with('inventoryItem')
                ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                ->where('reference_type', OrderItem::class)->where('reference_id', $item->getKey())
                ->where('type', InventoryMovementType::OrderConsumption->value)
                ->orderBy('inventory_item_id')->orderBy('id');
            $originalMovements = $this->locked($movementQuery, $lock)->get();
            $reversalQuery = InventoryMovement::query()->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)->whereIn('reversal_of_id', $originalMovements->pluck('id'))
                ->orderBy('inventory_item_id')->orderBy('id');
            $reversals = $this->locked($reversalQuery, $lock)->get()->groupBy('reversal_of_id');
            $movementPairs = [];
            foreach ($originalMovements as $original) {
                $matches = $reversals->get($original->getKey(), collect());
                if ($matches->count() !== 1) {
                    throw new DomainException('Un consumo de inventario legacy no posee una única reversión.');
                }
                $reversal = $matches->first();
                if ($reversal->type !== InventoryMovementType::Reversal
                    || $reversal->inventory_item_id !== $original->inventory_item_id
                    || $reversal->quantity !== $original->quantity
                    || data_get($reversal->metadata, 'direction') !== 1
                    || data_get($reversal->metadata, 'reversed_type') !== InventoryMovementType::OrderConsumption->value) {
                    throw new DomainException('La reversión de inventario legacy no coincide con su consumo original.');
                }
                $originalAllocations = collect(data_get($original->metadata, 'batch_allocations', []));
                $restoredAllocations = collect(data_get($reversal->metadata, 'restored_batch_allocations', []));
                if ($originalAllocations->isEmpty() || $originalAllocations->values()->all() !== $restoredAllocations->values()->all()) {
                    throw new DomainException('Los lotes restaurados no coinciden con las asignaciones originales.');
                }
                $batchIds = [...$batchIds, ...$originalAllocations->pluck('batch_id')->all()];
                $requirements[$original->inventory_item_id] = BigDecimal::of($requirements[$original->inventory_item_id] ?? '0')
                    ->plus($original->quantity);
                $movementIds[] = $original->getKey();
                $movementIds[] = $reversal->getKey();
                $movementPairs[] = compact('original', 'reversal');
            }

            $reservationTotals = $reservations->groupBy('inventory_item_id')->map(
                fn (Collection $group): string => (string) $group->reduce(
                    fn (BigDecimal $sum, InventoryReservation $reservation): BigDecimal => $sum->plus($reservation->quantity),
                    BigDecimal::zero(),
                ),
            );
            $movementTotals = $originalMovements->groupBy('inventory_item_id')->map(
                fn (Collection $group): string => (string) $group->reduce(
                    fn (BigDecimal $sum, InventoryMovement $movement): BigDecimal => $sum->plus($movement->quantity),
                    BigDecimal::zero(),
                ),
            );
            if ($reservationTotals->count() !== $movementTotals->count()
                || $reservationTotals->contains(fn (string $quantity, int $inventoryItemId): bool => ! BigDecimal::of($quantity)->isEqualTo(BigDecimal::of($movementTotals->get($inventoryItemId, '-1'))))) {
                throw new DomainException('Reservas y consumos legacy no reconcilian por artículo de inventario.');
            }

            $items[] = [
                'item' => $item,
                'dispatch' => $dispatch,
                'previous_status' => $previousStatus,
                'reservations' => $reservations,
                'movement_pairs' => $movementPairs,
            ];
        }
        if ($items === []) {
            throw new DomainException('La anulación legacy no contiene productos restaurables con evidencia completa.');
        }

        $inventoryItemIds = collect(array_keys($requirements))->sort()->values();
        $stockQuery = InventoryStock::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->whereIn('inventory_item_id', $inventoryItemIds)->orderBy('inventory_item_id');
        $stocks = $this->locked($stockQuery, $lock)->get()->keyBy('inventory_item_id');
        $activeReservationQuery = InventoryReservation::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->whereIn('inventory_item_id', $inventoryItemIds)
            ->where('status', InventoryReservationStatus::Reserved->value)->orderBy('inventory_item_id')->orderBy('id');
        $activeReservations = $this->locked($activeReservationQuery, $lock)->get()->groupBy('inventory_item_id');
        $batchQuery = InventoryBatch::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->whereIn('inventory_item_id', $inventoryItemIds)
            ->orderBy('inventory_item_id')->orderBy('id');
        $batches = $this->locked($batchQuery, $lock)->get();
        if (collect($batchIds)->diff($batches->pluck('id'))->isNotEmpty()) {
            throw new DomainException('Falta un lote histórico utilizado por la venta legacy.');
        }
        $availability = [];
        foreach ($inventoryItemIds as $inventoryItemId) {
            $stock = $stocks->get($inventoryItemId);
            if (! $stock) {
                throw new DomainException('Falta el saldo de un artículo requerido por la venta legacy.');
            }
            $reserved = $activeReservations->get($inventoryItemId, collect())->reduce(
                fn (BigDecimal $sum, InventoryReservation $reservation): BigDecimal => $sum->plus($reservation->quantity),
                BigDecimal::zero(),
            );
            $result = $this->availability->forStock($stock, $batches->where('inventory_item_id', $inventoryItemId), (string) $reserved);
            if (BigDecimal::of($result->availableQuantity)->isLessThan($requirements[$inventoryItemId])) {
                throw new DomainException('Stock insuficiente para recuperar íntegramente la venta legacy.');
            }
            $availability[$inventoryItemId] = [
                'required' => (string) $requirements[$inventoryItemId],
                'physical' => $result->physicalQuantity,
                'expired' => $result->expiredQuantity,
                'reserved' => $result->reservedQuantity,
                'available' => $result->availableQuantity,
            ];
        }

        return [
            'order' => $order,
            'payments' => $payments,
            'payment_pairs' => $paymentPairs,
            'sessions' => $sessions,
            'transfers' => $transfers,
            'dispatches' => $dispatches,
            'items' => $items,
            'stocks' => $stocks,
            'batches' => $batches,
            'availability' => $availability,
            'movement_ids' => $movementIds,
            'batch_ids' => $batchIds,
        ];
    }

    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function report(array $evidence): array
    {
        /** @var Order $order */
        $order = $evidence['order'];

        return [
            'valid' => true,
            'recovery_type' => 'legacy_administrative',
            'order' => [
                'id' => $order->getKey(),
                'ulid' => $order->ulid,
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'operational_number' => $order->operational_number,
                'status' => $order->status->value,
                'total' => $order->total,
                'closed_at' => $order->closed_at?->toDateTimeString(),
                'cancelled_at' => $order->cancelled_at?->toDateTimeString(),
                'cancelled_by' => $order->cancelled_by,
                'cancellation_reason' => $order->cancellation_reason,
            ],
            'payments' => collect($evidence['payment_pairs'])->map(fn (array $pair): array => [
                'original_payment_id' => $pair['original']->getKey(),
                'reversal_payment_id' => $pair['reversal']->getKey(),
                'cash_session_id' => $pair['original']->cash_session_id,
                'kitchen_dispatch_id' => $pair['original']->kitchen_dispatch_id,
                'method' => $pair['original']->method->value,
                'amount' => $pair['original']->amount,
                'economic_paid_at' => $pair['original']->paid_at->toDateTimeString(),
            ])->all(),
            'cash_session_transfer_ids' => $evidence['transfers']->pluck('id')->all(),
            'cash_sessions' => $evidence['sessions']->map(fn (CashSession $session): array => [
                'id' => $session->getKey(),
                'status' => $session->status->value,
            ])->values()->all(),
            'cash_movement_ids' => [],
            'dispatches' => collect($evidence['dispatches'])->map(fn (KitchenDispatch $dispatch): array => [
                'id' => $dispatch->getKey(),
                'current_status' => $dispatch->status->value,
                'previous_dispatch_status' => KitchenDispatchStatus::Settled->value,
                'total' => $dispatch->total,
            ])->all(),
            'items' => collect($evidence['items'])->map(fn (array $item): array => [
                'id' => $item['item']->getKey(),
                'current_status' => $item['item']->status->value,
                'previous_item_status' => $item['previous_status']->value,
                'kitchen_dispatch_id' => $item['dispatch']->getKey(),
                'reservation_ids' => $item['reservations']->pluck('id')->all(),
                'inventory_pairs' => collect($item['movement_pairs'])->map(fn (array $pair): array => [
                    'original_movement_id' => $pair['original']->getKey(),
                    'reversal_movement_id' => $pair['reversal']->getKey(),
                    'inventory_item_id' => $pair['original']->inventory_item_id,
                    'quantity' => $pair['original']->quantity,
                    'unit_cost' => $pair['original']->unit_cost,
                    'batch_allocations' => data_get($pair['original']->metadata, 'batch_allocations', []),
                ])->all(),
            ])->all(),
            'availability' => $evidence['availability'],
            'verified_at' => now()->toIso8601String(),
        ];
    }

    private function wasCancelledWithOrder(OrderItem $item, Order $order): bool
    {
        return $item->cancelled_at?->equalTo($order->cancelled_at) === true
            && (int) $item->cancelled_by === (int) $order->cancelled_by
            && $item->cancellation_reason === $order->cancellation_reason;
    }

    private function previousItemStatus(OrderItem $item): OrderItemStatus
    {
        return match (true) {
            $item->served_at !== null => OrderItemStatus::Served,
            $item->ready_at !== null => OrderItemStatus::Ready,
            $item->preparing_at !== null => OrderItemStatus::Preparing,
            $item->sent_at !== null => OrderItemStatus::Sent,
            default => throw new DomainException('No existe evidencia temporal suficiente para reconstruir el estado previo del producto.'),
        };
    }

    private function paymentIdempotencyKey(Order $order, Payment $payment): string
    {
        return "legacy-restore-order-{$order->getKey()}-payment-{$payment->getKey()}";
    }

    private function assertExpectations(array $report, array $expectations): void
    {
        foreach ($expectations as $path => $expected) {
            if (data_get($report, $path) !== $expected) {
                throw new DomainException('Legacy evidence does not match the expected manifest at '.$path.'.');
            }
        }
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function locked(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }
}
