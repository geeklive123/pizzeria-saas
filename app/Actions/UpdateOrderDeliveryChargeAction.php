<?php

namespace App\Actions;

use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\DynamicQrIntent;
use App\Models\Order;
use App\Models\OrderDeliveryCharge;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OnlineOrderCommercialGuard;
use App\Services\OrderFinancialService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateOrderDeliveryChargeAction
{
    public function __construct(private readonly CompanyAccessService $access, private readonly OrderFinancialService $financials) {}

    public function execute(Order $order, User $user, string $amount, bool $enabled = true): void
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $amount = (string) BigDecimal::of($amount)->toScale(2);

        if (! $enabled) {
            $amount = '0.00';
        }

        DB::transaction(function () use ($order, $user, $amount, $enabled): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            app(OnlineOrderCommercialGuard::class)->assertEditable($order);
            if ($order->type !== OrderType::Takeaway || ! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                throw new DomainException('Solo se puede configurar el envío de un pedido para llevar activo.');
            }
            if (BigDecimal::of($amount)->isNegative()) {
                throw new DomainException('El importe de envío no puede ser negativo.');
            }
            if ($order->payments()->where('status', PaymentStatus::Completed->value)->exists()
                || DynamicQrIntent::query()->where('order_id', $order->id)->where(fn ($q) => $q->whereNotNull('active_order_id')->orWhereIn('status', [DynamicQrStatus::GenerationUnknown->value, DynamicQrStatus::ReconciliationRequired->value]))->exists()) {
                throw new DomainException('No se puede modificar el envío con pagos o QR pendientes.');
            }
            $charge = OrderDeliveryCharge::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $previous = $charge?->amount ?? '0.00';
            $previousEnabled = $charge?->enabled ?? false;
            if (BigDecimal::of($previous)->isEqualTo($amount) && $previousEnabled === $enabled) {
                return;
            }
            if (! $charge) {
                $charge = OrderDeliveryCharge::query()->create(['company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id, 'amount' => $amount, 'enabled' => $enabled, 'created_by' => $user->id, 'updated_by' => $user->id]);
            } else {
                $charge->update(['amount' => $amount, 'enabled' => $enabled, 'updated_by' => $user->id]);
            }
            if ($charge->kitchen_dispatch_id === null && $enabled) {
                $pending = $order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment->value)->lockForUpdate()->first();
                if ($pending) {
                    $charge->update(['kitchen_dispatch_id' => $pending->id]);
                }
            }
            DB::table('order_delivery_charge_audits')->insert(['order_delivery_charge_id' => $charge->id, 'previous_amount' => $previous, 'new_amount' => $amount, 'previous_enabled' => $previousEnabled, 'new_enabled' => $enabled, 'user_id' => $user->id, 'created_at' => now()]);
            if ($charge->kitchen_dispatch_id) {
                $dispatch = $order->kitchenDispatches()->lockForUpdate()->findOrFail($charge->kitchen_dispatch_id);
                $this->financials->applyToDispatch($dispatch, $dispatch->discount_percentage);
            }
            $this->financials->recalculateOrder($order);
        }, 3);
    }
}
