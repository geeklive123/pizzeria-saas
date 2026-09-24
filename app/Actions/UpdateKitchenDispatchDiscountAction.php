<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\Permission;
use App\Enums\TableChargeMode;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderFinancialService;
use App\Services\OrderTotalsService;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateKitchenDispatchDiscountAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly OrderFinancialService $financials,
        private readonly OrderTotalsService $totals,
    ) {}

    public function execute(KitchenDispatch $dispatch, User $user, int|string $discountPercentage): KitchenDispatch
    {
        $dispatch->loadMissing('order.company');
        $this->access->ensure($user, $dispatch->order->company, Permission::ManageOrders);
        $this->access->ensure($user, $dispatch->order->company, Permission::ApplyOrderDiscounts);

        return DB::transaction(function () use ($dispatch, $discountPercentage): KitchenDispatch {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($dispatch->order_id);
            $dispatch = KitchenDispatch::query()
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->findOrFail($dispatch->getKey());

            if ($order->charge_mode !== TableChargeMode::PerBatch
                || $dispatch->status !== KitchenDispatchStatus::AwaitingPayment) {
                throw new DomainException('Solo una tanda pendiente de pago admite cambios de descuento.');
            }

            if ($dispatch->payments()->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) {
                throw new DomainException('El descuento de la tanda no puede modificarse después de registrar el primer pago.');
            }

            $this->financials->applyToDispatch($dispatch, $discountPercentage);
            $this->totals->recalculate($order);

            return $dispatch->refresh();
        });
    }
}
