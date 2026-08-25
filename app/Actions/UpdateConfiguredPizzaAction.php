<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
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

class UpdateConfiguredPizzaAction
{
    public function __construct(
        private readonly PizzaCompositionService $composition,
        private readonly ReleaseInventoryReservationAction $release,
        private readonly ReserveInventoryForOrderItemAction $reserve,
        private readonly OrderTotalsService $totals,
        private readonly CompanyAccessService $access,
    ) {}

    /** @param list<array<string,mixed>> $sections @param list<array<string,mixed>> $modifiers */
    public function execute(OrderItem $item, array $sections, int|string $quantity, User $user, OrderType $fulfillment, array $modifiers = [], ?string $notes = null): OrderItem
    {
        $this->access->ensure($user, $item->company, Permission::ManageOrders);
        $quantity = BigDecimal::of($quantity)->toScale(3, RoundingMode::HalfUp);
        if ($quantity->isLessThanOrEqualTo(0)) {
            throw new DomainException('La cantidad debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($item, $sections, $quantity, $fulfillment, $modifiers, $notes): OrderItem {
            $item = OrderItem::query()->with(['company', 'order', 'sections', 'modifiers'])->lockForUpdate()->findOrFail($item->id);
            if ($item->status !== OrderItemStatus::Draft || $item->order->status !== OrderStatus::Open) {
                throw new DomainException('Una línea enviada no se edita; debe cancelarse y agregarse nuevamente.');
            }

            $result = $this->composition->compose($item->company, $sections, $modifiers, $fulfillment);
            $this->release->execute($item, $item->quantity);
            $item->modifiers()->delete();
            $item->sections()->delete();
            $item->forceFill([
                'product_variant_id' => $result['primary_variant']->id,
                'quantity' => (string) $quantity,
                'unit_price' => $result['unit_price'],
                'line_total' => (string) BigDecimal::of($result['unit_price'])->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp),
                'fulfillment_type' => $fulfillment,
                'notes' => $notes,
                'configuration_snapshot' => $result['snapshot'],
            ])->save();

            $sectionModels = [];
            foreach ($result['sections'] as $section) {
                $sectionModels[$section['position']] = OrderItemSection::query()->create([
                    'company_id' => $item->company_id, 'branch_id' => $item->branch_id, 'order_item_id' => $item->id,
                    'product_variant_id' => $section['variant']->id, 'fraction_numerator' => $section['fraction_numerator'],
                    'fraction_denominator' => $section['fraction_denominator'], 'position' => $section['position'],
                    'unit_price_snapshot' => $section['unit_price_snapshot'], 'product_name_snapshot' => $section['product_name_snapshot'],
                    'variant_name_snapshot' => $section['variant_name_snapshot'],
                ]);
            }
            foreach ($result['modifiers'] as $modifier) {
                OrderItemModifier::query()->create([
                    'company_id' => $item->company_id, 'branch_id' => $item->branch_id, 'order_item_id' => $item->id,
                    'order_item_section_id' => $modifier['section_position'] ? $sectionModels[$modifier['section_position']]->id : null,
                    'modifier_option_id' => $modifier['option']->id, 'type' => $modifier['type'],
                    'name_snapshot' => $modifier['name_snapshot'], 'price_delta_snapshot' => $modifier['price_delta_snapshot'],
                    'inventory_item_id' => $modifier['inventory_item']->id, 'quantity_snapshot' => $modifier['quantity_snapshot'],
                    'unit_id' => $modifier['unit_id'],
                ]);
            }

            $this->reserve->execute($item->load('productVariant'), (string) $quantity);
            $this->totals->recalculate($item->order);

            return $item->refresh()->load(['sections.productVariant.product', 'modifiers']);
        });
    }
}
