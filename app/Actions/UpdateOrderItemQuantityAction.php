<?php

namespace App\Actions;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateOrderItemQuantityAction
{
    public function __construct(private readonly ReserveInventoryForOrderItemAction $reserve, private readonly ReleaseInventoryReservationAction $release, private readonly OrderTotalsService $totals, private readonly CompanyAccessService $access, private readonly UpdateConfiguredPizzaAction $configuredPizza) {}

    public function execute(OrderItem $item, int|string $newQuantity, User $user, ?OrderType $fulfillment = null, ?string $notes = null): OrderItem
    {
        $this->access->ensure($user, $item->company, Permission::ManageOrders);
        $new = BigDecimal::of($newQuantity)->toScale(3, RoundingMode::HalfUp);
        if ($new->isLessThanOrEqualTo(0)) {
            throw new DomainException('La cantidad del producto debe ser mayor que cero.');
        }

        if ($item->sections()->exists()) {
            $item->load(['sections.productVariant', 'modifiers.option', 'modifiers.section']);
            $sections = $item->sections->map(fn ($section): array => [
                'product_variant' => $section->productVariant,
                'fraction_numerator' => $section->fraction_numerator,
                'fraction_denominator' => $section->fraction_denominator,
            ])->all();
            $modifiers = $item->modifiers->map(fn ($modifier): array => [
                'option' => $modifier->option,
                'section_position' => $modifier->section?->position,
            ])->all();

            return $this->configuredPizza->execute(
                $item,
                $sections,
                (string) $new,
                $user,
                $fulfillment ?? $item->fulfillment_type,
                $modifiers,
                $notes,
            );
        }

        return DB::transaction(function () use ($item, $new, $fulfillment, $notes): OrderItem {
            $item = OrderItem::query()->with(['order', 'productVariant'])->lockForUpdate()->findOrFail($item->id);
            if ($item->status !== OrderItemStatus::Draft || $item->order->status !== OrderStatus::Open) {
                throw new DomainException('Solo un producto en borrador de un pedido abierto puede editarse.');
            }
            $current = BigDecimal::of($item->quantity);
            if ($new->isGreaterThan($current)) {
                $this->reserve->execute($item, (string) $new->minus($current));
            } elseif ($new->isLessThan($current)) {
                $this->release->execute($item, (string) $current->minus($new));
            }
            $snapshot = $item->configuration_snapshot;
            if (($snapshot['type'] ?? null) === 'promotion') {
                $snapshot['components'] = collect($snapshot['components'] ?? [])->map(function (array $component) use ($new): array {
                    $perPromotion = $component['quantity_per_promotion'] ?? $component['quantity'];
                    $component['quantity_applied'] = (string) BigDecimal::of($perPromotion)
                        ->multipliedBy($new)->toScale(3, RoundingMode::HalfUp);

                    return $component;
                })->all();
            }
            $item->forceFill([
                'quantity' => (string) $new,
                'line_total' => (string) BigDecimal::of($item->unit_price)->multipliedBy($new)->toScale(2, RoundingMode::HalfUp),
                'fulfillment_type' => $fulfillment ?? $item->fulfillment_type,
                'notes' => $notes,
                'configuration_snapshot' => $snapshot,
            ])->save();
            $this->totals->recalculate($item->order);

            return $item->refresh();
        });
    }
}
