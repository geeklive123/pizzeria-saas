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
use DomainException;
use Illuminate\Support\Facades\DB;

class MarkOrderReadyForPaymentAction
{
    public function __construct(private readonly CompanyAccessService $access, private readonly OrderFinancialService $financials) {}

    public function execute(Order $order, User $user, int|string|null $discountPercentage = null): Order
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $discountWasProvided = $discountPercentage !== null && $discountPercentage !== '';
        if ($discountWasProvided) {
            $this->access->ensure($user, $order->company, Permission::ApplyOrderDiscounts);
        }

        return DB::transaction(function () use ($order, $discountPercentage, $discountWasProvided): Order {
            $order = Order::query()->with('items.sections')->lockForUpdate()->findOrFail($order->id);
            if ($order->type !== OrderType::DineIn || $order->charge_mode !== TableChargeMode::AtEnd) {
                throw new DomainException('Esta cuenta no utiliza cobro al final.');
            }
            if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true) || ! $order->items()->exists()) {
                throw new DomainException('Solo una cuenta activa con productos puede prepararse para el cobro.');
            }
            if ($order->status === OrderStatus::ReadyForPayment && $order->payments()->exists()) {
                throw new DomainException('El descuento no puede modificarse después de registrar el primer pago.');
            }
            if ($order->items()->whereIn('status', [OrderItemStatus::Draft->value, OrderItemStatus::PendingPayment->value])->exists()
                || $order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment->value)->exists()) {
                throw new DomainException('Confirma todos los productos antes de solicitar la cuenta.');
            }
            $effectiveDiscount = $discountWasProvided ? $discountPercentage : $order->discount_percentage;
            $this->financials->applyToOrder($order, $effectiveDiscount);
            if ($order->status === OrderStatus::Open) {
                $order->forceFill(['status' => OrderStatus::ReadyForPayment])->save();
            }

            return $order->refresh();
        });
    }
}
