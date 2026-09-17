<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\InventoryMovement;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderFinancialService;
use App\Services\OrderTotalsService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelKitchenDispatchItemAction
{
    public function __construct(
        private readonly ReleaseInventoryReservationAction $release,
        private readonly ReverseInventoryMovementAction $reverseMovement,
        private readonly OrderFinancialService $financials,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        OrderItem $item,
        User $user,
        string $reason,
        ?OrderCancellationAudit $parentAudit = null,
    ): OrderItem {
        $this->access->ensure($user, $item->company, Permission::CancelOrders);
        if (blank($reason)) {
            throw new DomainException('Indica el motivo de la anulación.');
        }

        return DB::transaction(function () use ($item, $user, $reason, $parentAudit): OrderItem {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $this->ensureOrderCanChange($order);

            $link = KitchenDispatchItem::query()->where('order_item_id', $item->getKey())->first();
            if (! $link) {
                throw new DomainException('El producto no pertenece a una tanda enviada.');
            }

            $dispatch = KitchenDispatch::query()->lockForUpdate()->findOrFail($link->kitchen_dispatch_id);
            $item = OrderItem::query()->with(['company', 'reservations'])->lockForUpdate()->findOrFail($item->getKey());
            if ($item->status === OrderItemStatus::Cancelled) {
                throw new DomainException('Este producto ya fue anulado.');
            }
            if ((int) $dispatch->order_id !== (int) $order->getKey()
                || (int) $item->order_id !== (int) $order->getKey()
                || (int) $item->company_id !== (int) $dispatch->company_id
                || (int) $item->branch_id !== (int) $dispatch->branch_id) {
                throw new DomainException('El producto y la tanda no pertenecen al mismo pedido.');
            }

            $reservationSnapshots = $item->reservations
                ->where('status', InventoryReservationStatus::Reserved)
                ->map(fn ($reservation): array => [
                    'id' => $reservation->getKey(),
                    'inventory_item_id' => $reservation->inventory_item_id,
                    'quantity' => $reservation->quantity,
                ])->values()->all();
            $audit = OrderCancellationAudit::query()->create([
                'company_id' => $item->company_id,
                'branch_id' => $item->branch_id,
                'order_id' => $order->getKey(),
                'kitchen_dispatch_id' => $dispatch->getKey(),
                'order_item_id' => $item->getKey(),
                'parent_id' => $parentAudit?->getKey(),
                'scope' => OrderCancellationScope::KitchenDispatchItem,
                'snapshot' => [
                    'previous_status' => $item->status->value,
                    'reservations' => $reservationSnapshots,
                    'inventory_reversals' => [],
                    'dispatch_auto_cancelled' => false,
                    'dispatch_previous_status' => $dispatch->status->value,
                ],
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ]);

            if ($reservationSnapshots !== []) {
                $this->release->execute($item, $item->quantity);
            }

            $movements = InventoryMovement::query()
                ->forCompany($item->company_id)
                ->where('branch_id', $item->branch_id)
                ->where('reference_type', OrderItem::class)
                ->where('reference_id', $item->getKey())
                ->where('type', InventoryMovementType::OrderConsumption->value)
                ->whereDoesntHave('reversals')
                ->orderBy('inventory_item_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $inventoryReversals = [];
            foreach ($movements as $movement) {
                $reversal = $this->reverseMovement->execute(
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

            $cancelledAt = now();
            $item->forceFill([
                'status' => OrderItemStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->financials->recalculateDispatchFromActiveItems($dispatch);
            $hasActiveItems = $dispatch->items()
                ->whereHas('orderItem', fn ($query) => $query->where('status', '!=', OrderItemStatus::Cancelled->value))
                ->exists();
            if (! $hasActiveItems) {
                $dispatch->forceFill([
                    'status' => KitchenDispatchStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancelled_by' => $user->getKey(),
                    'cancellation_reason' => $reason,
                ])->save();
            }

            $audit->forceFill(['snapshot' => [
                ...$audit->snapshot,
                'inventory_reversals' => $inventoryReversals,
                'dispatch_auto_cancelled' => ! $hasActiveItems,
            ]])->save();

            $this->totals->recalculate($order);

            return $item->refresh();
        }, attempts: 3);
    }

    private function ensureOrderCanChange(Order $order): void
    {
        if ($order->status === OrderStatus::Paid) {
            throw new DomainException('Este pedido ya fue pagado. Para modificar productos debe utilizar un flujo de reversión/reembolso.');
        }
        if ($order->status === OrderStatus::Cancelled) {
            throw new DomainException('Este pedido está anulado y no permite modificar productos.');
        }
        if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
            throw new DomainException('Este pedido no permite anulación parcial.');
        }
    }
}
