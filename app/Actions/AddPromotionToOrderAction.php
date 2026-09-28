<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use App\Services\PromotionPizzaSelectionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddPromotionToOrderAction
{
    public function __construct(
        private readonly ReserveInventoryForOrderItemAction $reserve,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
        private readonly PromotionPizzaSelectionService $pizzaSelections,
    ) {}

    public function execute(Order $order, Promotion $promotion, int|string $quantity, User $user, ?OrderType $fulfillment = null, ?string $notes = null, array $pizzas = []): OrderItem
    {
        $this->access->ensure($user, $order->company, Permission::ManageOrders);
        $quantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        if ($quantity->isLessThanOrEqualTo(0) || (int) $promotion->company_id !== (int) $order->company_id) {
            throw new DomainException('La cantidad o la promoción seleccionada no es válida.');
        }

        return DB::transaction(function () use ($order, $promotion, $quantity, $user, $fulfillment, $notes, $pizzas): OrderItem {
            $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->getKey());
            $promotion = Promotion::query()->with(['company', 'productVariant.product', 'components.inventoryItem.unit', 'selectionGroups.category'])
                ->lockForUpdate()->findOrFail($promotion->getKey());
            if ($order->status !== OrderStatus::Open || ! $promotion->isCurrentlyActive()
                || ! $promotion->productVariant->is_active || ! $promotion->productVariant->product->is_active
                || ($promotion->components->isEmpty() && $promotion->selectionGroups->isEmpty())) {
                throw new DomainException('La promoción ya no está disponible.');
            }

            if ($promotion->selectionGroups->isNotEmpty() && ! $quantity->isEqualTo(1)) {
                throw new DomainException('Las promociones con pizzas se agregan una unidad por configuración.');
            }

            $fulfillment ??= $order->type;
            $configured = $this->pizzaSelections->compose($promotion, $pizzas, $fulfillment);
            $basePrice = BigDecimal::of($promotion->productVariant->price)->toScale(2, RoundingMode::HalfUp);
            $price = $basePrice->plus($configured['extras_total'])->toScale(2, RoundingMode::HalfUp);
            $fixedRequirements = $promotion->components->map(fn ($component): array => [
                'inventory_item_id' => $component->inventory_item_id,
                'inventory_item_ulid' => $component->inventoryItem->ulid,
                'inventory_item_name' => $component->inventoryItem->name,
                'unit_id' => $component->inventoryItem->unit_id,
                'unit_symbol' => $component->inventoryItem->unit->symbol,
                'quantity' => $component->quantity,
            ])->values()->all();
            $requirements = collect([...$fixedRequirements, ...$configured['requirements']])->groupBy('inventory_item_id')
                ->map(function ($rows): array {
                    $first = $rows->first();
                    $first['quantity'] = (string) $rows->reduce(
                        fn (BigDecimal $total, array $row): BigDecimal => $total->plus($row['quantity']),
                        BigDecimal::zero(),
                    )->toScale(3, RoundingMode::HalfUp);

                    return $first;
                })->sortKeys()->values()->all();
            $snapshotComponents = collect($fixedRequirements)->map(fn (array $component): array => [
                ...$component,
                'quantity_per_promotion' => $component['quantity'],
                'quantity_applied' => (string) BigDecimal::of($component['quantity'])
                    ->multipliedBy($quantity)->toScale(3, RoundingMode::HalfUp),
            ])->all();

            $item = OrderItem::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'product_variant_id' => $promotion->product_variant_id,
                'promotion_id' => $promotion->getKey(),
                'quantity' => (string) $quantity,
                'unit_price' => (string) $price,
                'line_total' => (string) $price->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp),
                'fulfillment_type' => $fulfillment,
                'requires_preparation' => $promotion->selectionGroups->isNotEmpty(),
                'status' => OrderItemStatus::Draft,
                'notes' => $notes,
                'configuration_snapshot' => [
                    'type' => 'promotion',
                    'promotion' => [
                        'id' => $promotion->getKey(),
                        'ulid' => $promotion->ulid,
                        'name' => $promotion->productVariant->product->name,
                        'base_price' => (string) $basePrice,
                        'extras_price' => $configured['extras_total'],
                        'unit_price' => (string) $price,
                    ],
                    'selection_groups' => $promotion->selectionGroups->map(fn ($group): array => [
                        'ulid' => $group->ulid,
                        'category_id' => $group->category->getKey(),
                        'category_ulid' => $group->category->ulid,
                        'category' => $group->category->name,
                        'size_key' => $group->size_key,
                        'quantity' => $group->quantity,
                        'allow_repeated_flavors' => $group->allow_repeated_flavors,
                        'allow_fusions' => $group->allow_fusions,
                        'allow_extras' => $group->allow_extras,
                        'allow_removals' => $group->allow_removals,
                    ])->values()->all(),
                    'pizzas' => $configured['pizzas'],
                    'requirements' => $requirements,
                    'components' => $snapshotComponents,
                    'pricing' => [
                        'base' => (string) $basePrice,
                        'extras' => $configured['extras_total'],
                        'total' => (string) $price,
                    ],
                ],
                'created_by' => $user->getKey(),
            ]);
            $this->reserve->execute($item->load('productVariant'), (string) $quantity);
            $this->totals->recalculate($order);

            return $item->refresh();
        });
    }
}
