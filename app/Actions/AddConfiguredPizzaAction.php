<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderItemSection;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use App\Services\PizzaCompositionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddConfiguredPizzaAction
{
    public function __construct(
        private readonly PizzaCompositionService $composition,
        private readonly ReserveInventoryForOrderItemAction $reserve,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    /** @param list<array<string,mixed>> $sections @param list<array<string,mixed>> $modifiers */
    public function execute(Order $order, array $sections, int|string $quantity, User $user, ?OrderType $fulfillment = null, array $modifiers = [], ?string $notes = null, array $toppings = []): OrderItem
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $quantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        if ($quantity->isLessThanOrEqualTo(0)) {
            throw new DomainException('La cantidad debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($order, $sections, $quantity, $user, $fulfillment, $modifiers, $notes, $toppings): OrderItem {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::Open) {
                throw new DomainException('Solo una cuenta abierta puede recibir productos.');
            }

            $fulfillment ??= $order->type;
            $result = $this->composition->compose($order->company, $sections, $modifiers, $fulfillment, $toppings);
            $lineTotal = BigDecimal::of($result['unit_price'])->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp);
            $item = OrderItem::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'product_variant_id' => $result['primary_variant']->id,
                'quantity' => (string) $quantity,
                'unit_price' => $result['unit_price'],
                'line_total' => (string) $lineTotal,
                'fulfillment_type' => $fulfillment,
                'requires_preparation' => true,
                'status' => OrderItemStatus::Draft,
                'notes' => $notes,
                'configuration_snapshot' => $result['snapshot'],
                'created_by' => $user->id,
            ]);

            $sectionModels = [];
            foreach ($result['sections'] as $section) {
                $sectionModels[$section['position']] = OrderItemSection::query()->create([
                    'company_id' => $item->company_id,
                    'branch_id' => $item->branch_id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => $section['variant']->id,
                    'fraction_numerator' => $section['fraction_numerator'],
                    'fraction_denominator' => $section['fraction_denominator'],
                    'position' => $section['position'],
                    'unit_price_snapshot' => $section['unit_price_snapshot'],
                    'product_name_snapshot' => $section['product_name_snapshot'],
                    'variant_name_snapshot' => $section['variant_name_snapshot'],
                ]);
            }

            foreach ($result['modifiers'] as $modifier) {
                OrderItemModifier::query()->create([
                    'company_id' => $item->company_id,
                    'branch_id' => $item->branch_id,
                    'order_item_id' => $item->id,
                    'order_item_section_id' => $modifier['section_position'] ? $sectionModels[$modifier['section_position']]->id : null,
                    'modifier_option_id' => $modifier['option']->id,
                    'type' => $modifier['type'],
                    'name_snapshot' => $modifier['name_snapshot'],
                    'price_delta_snapshot' => $modifier['price_delta_snapshot'],
                    'inventory_item_id' => $modifier['inventory_item']?->id,
                    'quantity_snapshot' => $modifier['quantity_snapshot'],
                    'unit_id' => $modifier['unit_id'],
                ]);
            }

            $this->reserve->execute($item->load('productVariant'), (string) $quantity);
            $this->totals->recalculate($order);

            return $item->refresh()->load(['sections.productVariant.product', 'modifiers']);
        });
    }
}
