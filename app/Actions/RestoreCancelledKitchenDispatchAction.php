<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderCancellationScope;
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

class RestoreCancelledKitchenDispatchAction
{
    public function __construct(
        private readonly RestoreCancelledKitchenDispatchItemAction $restoreItem,
        private readonly OrderFinancialService $financials,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(KitchenDispatch $dispatch, User $user, string $reason): KitchenDispatch
    {
        $this->authorize($dispatch, $user);
        if (blank($reason) || mb_strlen($reason) > 500) {
            throw new DomainException('Indica un motivo de restauración de hasta 500 caracteres.');
        }

        return DB::transaction(function () use ($dispatch, $user, $reason): KitchenDispatch {
            $order = Order::query()->lockForUpdate()->findOrFail($dispatch->order_id);
            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Solo se pueden restaurar tandas de pedidos abiertos o listos para pagar.');
            }
            $dispatch = KitchenDispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());
            if ($dispatch->status !== KitchenDispatchStatus::Cancelled) {
                throw new DomainException('Esta tanda no está anulada o ya fue restaurada.');
            }

            $audit = OrderCancellationAudit::query()
                ->where('company_id', $dispatch->company_id)
                ->where('branch_id', $dispatch->branch_id)
                ->where('order_id', $order->getKey())
                ->where('kitchen_dispatch_id', $dispatch->getKey())
                ->where('scope', OrderCancellationScope::KitchenDispatch->value)
                ->latest('id')->lockForUpdate()->first();
            if (! $audit) {
                throw new DomainException('Esta anulación no posee auditoría restaurable; puede corresponder a datos legacy.');
            }
            if ($audit->restored_at !== null) {
                throw new DomainException('Esta anulación ya fue restaurada.');
            }

            $children = $audit->children()->orderBy('order_item_id')->lockForUpdate()->get();
            if ($children->isEmpty()) {
                throw new DomainException('La auditoría de la tanda no contiene productos restaurables.');
            }
            foreach ($children as $child) {
                $item = OrderItem::query()->lockForUpdate()->findOrFail($child->order_item_id);
                $this->restoreItem->execute($item, $user, $reason, $child);
            }

            $previousStatus = KitchenDispatchStatus::tryFrom((string) ($audit->snapshot['previous_status'] ?? ''));
            if (! $previousStatus || $previousStatus === KitchenDispatchStatus::Cancelled) {
                throw new DomainException('La auditoría no contiene un estado previo válido para la tanda.');
            }
            $dispatch->forceFill(['status' => $previousStatus])->save();
            $audit->forceFill([
                'restored_at' => now(),
                'restored_by' => $user->getKey(),
                'restoration_reason' => $reason,
            ])->save();
            $this->financials->recalculateDispatchFromActiveItems($dispatch);
            $this->totals->recalculate($order);

            return $dispatch->refresh();
        }, attempts: 3);
    }

    private function authorize(KitchenDispatch $dispatch, User $user): void
    {
        $this->access->ensure($user, $dispatch->company, Permission::RestoreCancelledOrders);
        $role = $user->membershipFor($dispatch->company_id)?->role;
        if (! in_array($role, [MembershipRole::Owner, MembershipRole::Admin], true)) {
            throw new AuthorizationException('No tienes permiso para deshacer anulaciones.');
        }
    }
}
