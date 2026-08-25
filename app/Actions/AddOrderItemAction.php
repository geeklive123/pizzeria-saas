<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddOrderItemAction
{
    public function __construct(private readonly ReserveInventoryForOrderItemAction $reserve, private readonly OrderTotalsService $totals, private readonly CompanyAccessService $access, private readonly AddConfiguredPizzaAction $configuredPizza) {}

    public function execute(Order $order, ProductVariant $variant, int|string $quantity, User $user, ?OrderType $fulfillment = null, ?string $notes = null): OrderItem
    {
        $variant->loadMissing('product');
        if ($variant->product->type === ProductType::Pizza) {
            return $this->configuredPizza->execute($order, [[
                'product_variant' => $variant,
                'fraction_numerator' => 1,
                'fraction_denominator' => 1,
            ]], $quantity, $user, $fulfillment, notes: $notes);
        }

        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $quantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);

        if ($quantity->isLessThanOrEqualTo(0) || (int) $variant->company_id !== (int) $order->company_id || ! $variant->is_active) {
            throw new DomainException('La cantidad o la variante seleccionada no es válida.');
        }

        return DB::transaction(function () use ($order, $variant, $quantity, $user, $fulfillment, $notes): OrderItem {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::Open) {
                throw new DomainException('Solo un pedido abierto puede recibir productos.');
            }
            $lineTotal = BigDecimal::of($variant->price)->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp);
            $item = OrderItem::query()->create([
                'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                'product_variant_id' => $variant->id, 'quantity' => (string) $quantity,
                'unit_price' => $variant->price, 'line_total' => (string) $lineTotal,
                'fulfillment_type' => $fulfillment ?? $order->type,
                'requires_preparation' => $variant->requires_preparation,
                'status' => OrderItemStatus::Draft,
                'notes' => $notes, 'created_by' => $user->id,
            ]);
            $this->reserve->execute($item->load('productVariant'), (string) $quantity);
            $this->totals->recalculate($order);

            return $item->refresh();
        });
    }
}
