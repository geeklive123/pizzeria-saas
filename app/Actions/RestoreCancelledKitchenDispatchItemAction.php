<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderFinancialService;
use App\Services\OrderTotalsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RestoreCancelledKitchenDispatchItemAction
{
    public function __construct(
        private readonly RestoreCancellationInventoryAction $restoreInventory,
        private readonly OrderFinancialService $financials,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        OrderItem $item,
        User $user,
        string $reason,
        ?OrderCancellationAudit $knownAudit = null,
    ): OrderItem {
        $this->authorize($item, $user);
        if (blank($reason) || mb_strlen($reason) > 500) {
            throw new DomainException('Indica un motivo de restauración de hasta 500 caracteres.');
        }

        return DB::transaction(function () use ($item, $user, $reason, $knownAudit): OrderItem {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Solo se pueden restaurar productos de pedidos abiertos o listos para pagar.');
            }

            $item = OrderItem::query()->with(['company', 'order.branch'])->lockForUpdate()->findOrFail($item->getKey());
            if ($item->status !== OrderItemStatus::Cancelled) {
                throw new DomainException('Este producto no está anulado o ya fue restaurado.');
            }

            $auditQuery = OrderCancellationAudit::query()
                ->where('company_id', $item->company_id)
                ->where('branch_id', $item->branch_id)
                ->where('order_id', $order->getKey())
                ->where('order_item_id', $item->getKey())
                ->where('scope', OrderCancellationScope::KitchenDispatchItem->value);
            $audit = $knownAudit
                ? $auditQuery->whereKey($knownAudit->getKey())->lockForUpdate()->first()
                : $auditQuery->whereNull('parent_id')->latest('id')->lockForUpdate()->first();
            if (! $audit) {
                throw new DomainException('Esta anulación no posee auditoría restaurable; puede corresponder a datos legacy.');
            }
            if ($audit->restored_at !== null) {
                throw new DomainException('Esta anulación ya fue restaurada.');
            }

            $dispatch = KitchenDispatch::query()->lockForUpdate()->findOrFail($audit->kitchen_dispatch_id);
            $createdMovements = $this->restoreInventory->execute($audit, $item, $user);
            $previousStatus = OrderItemStatus::tryFrom((string) ($audit->snapshot['previous_status'] ?? ''));
            if (! $previousStatus || $previousStatus === OrderItemStatus::Cancelled) {
                throw new DomainException('La auditoría no contiene un estado previo válido para el producto.');
            }
            $item->forceFill(['status' => $previousStatus])->save();

            if (($audit->snapshot['dispatch_auto_cancelled'] ?? false) === true
                && $dispatch->status === KitchenDispatchStatus::Cancelled) {
                $previousDispatchStatus = KitchenDispatchStatus::tryFrom((string) ($audit->snapshot['dispatch_previous_status'] ?? ''));
                if ($previousDispatchStatus && $previousDispatchStatus !== KitchenDispatchStatus::Cancelled) {
                    $dispatch->forceFill(['status' => $previousDispatchStatus])->save();
                }
            }

            $audit->forceFill([
                'snapshot' => [
                    ...$audit->snapshot,
                    'restoration_inventory_movement_ids' => collect($createdMovements)->map->getKey()->all(),
                ],
                'restored_at' => now(),
                'restored_by' => $user->getKey(),
                'restoration_reason' => $reason,
            ])->save();
            $this->financials->recalculateDispatchFromActiveItems($dispatch);
            $this->totals->recalculate($order);

            return $item->refresh();
        }, attempts: 3);
    }

    private function authorize(OrderItem $item, User $user): void
    {
        $this->access->ensure($user, $item->company, Permission::RestoreCancelledOrders);
        $role = $user->membershipFor($item->company_id)?->role;
        if (! in_array($role, [MembershipRole::Owner, MembershipRole::Admin], true)) {
            throw new AuthorizationException('No tienes permiso para deshacer anulaciones.');
        }
    }
}
