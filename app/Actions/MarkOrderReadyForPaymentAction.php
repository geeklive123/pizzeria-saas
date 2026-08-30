<?php

namespace App\Actions;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TableChargeMode;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderFinancialService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class MarkOrderReadyForPaymentAction
{
    public function __construct(private readonly CompanyAccessService $access, private readonly OrderFinancialService $financials) {}

    public function execute(Order $order, User $user, int|string|null $discountPercentage = null): Order
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        if (filled($discountPercentage) && BigDecimal::of($discountPercentage)->isGreaterThan(0)) {
            $this->access->ensure($user, $order->company, Permission::ApplyOrderDiscounts);
        }

        return DB::transaction(function () use ($order, $discountPercentage): Order {
            $order = Order::query()->with('items.sections')->lockForUpdate()->findOrFail($order->id);
            if ($order->type !== OrderType::DineIn || $order->charge_mode !== TableChargeMode::AtEnd) {
                throw new DomainException('Esta cuenta no utiliza cobro al final.');
            }
            if ($order->status !== OrderStatus::Open || ! $order->items()->exists()) {
                throw new DomainException('Solo un pedido abierto con productos puede solicitar la cuenta.');
            }
            if ($order->items()->whereIn('status', [OrderItemStatus::Draft->value, OrderItemStatus::PendingPayment->value])->exists()
                || $order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment->value)->exists()) {
                throw new DomainException('Confirma todos los productos antes de solicitar la cuenta.');
            }
            $this->financials->applyToOrder($order, $discountPercentage);
            $order->forceFill(['status' => OrderStatus::ReadyForPayment])->save();

            return $order->refresh();
        });
    }
}
