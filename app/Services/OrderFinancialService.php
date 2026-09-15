<?php

namespace App\Services;

use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class OrderFinancialService
{
    public const DISCOUNT_THRESHOLD = '80.00';

    public function preview(iterable $items, int|string|null $percentage = null): array
    {
        $items = collect($items)->filter(fn (OrderItem $item): bool => $item->status->value !== 'cancelled')->values();
        $lines = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => $this->line($item)]);
        $gross = $this->sum($lines, 'gross');
        $pizzaBase = $this->sum($lines, 'pizza_base');
        $extras = $this->sum($lines, 'extras');
        $other = $this->sum($lines, 'other');
        $percentage = $this->percentage($percentage);

        if ($percentage->isGreaterThan(0) && ! $pizzaBase->isGreaterThan(self::DISCOUNT_THRESHOLD)) {
            throw new DomainException('El descuento solo está disponible cuando el subtotal base de pizzas es mayor a Bs 80,00.');
        }

        $discount = $pizzaBase->multipliedBy($percentage)->dividedBy('100', 2, RoundingMode::HalfUp);
        $allocated = BigDecimal::zero();
        $eligibleIds = $lines->filter(fn (array $line): bool => BigDecimal::of($line['pizza_base'])->isGreaterThan(0))->keys()->values();
        foreach ($eligibleIds as $index => $id) {
            $lineDiscount = $index === $eligibleIds->count() - 1
                ? $discount->minus($allocated)
                : BigDecimal::of($lines[$id]['pizza_base'])->multipliedBy($percentage)->dividedBy('100', 2, RoundingMode::HalfUp);
            $allocated = $allocated->plus($lineDiscount);
            $line = $lines[$id];
            $line['discount'] = $this->money($lineDiscount);
            $line['net'] = $this->money(BigDecimal::of($line['gross'])->minus($lineDiscount));
            $lines[$id] = $line;
        }

        return [
            'gross' => $this->money($gross),
            'pizza_base' => $this->money($pizzaBase),
            'extras' => $this->money($extras),
            'other' => $this->money($other),
            'discount_percentage' => $percentage->isZero() ? null : $this->money($percentage),
            'discount' => $this->money($discount),
            'total' => $this->money($gross->minus($discount)),
            'eligible' => $pizzaBase->isGreaterThan(self::DISCOUNT_THRESHOLD),
            'lines' => $lines,
        ];
    }

    public function applyToDispatch(KitchenDispatch $dispatch, int|string|null $percentage = null): KitchenDispatch
    {
        $dispatch->loadMissing('items.orderItem.sections');
        $snapshot = $this->preview($dispatch->items->map->orderItem, $percentage);
        foreach ($dispatch->items as $dispatchItem) {
            $line = $snapshot['lines'][$dispatchItem->order_item_id];
            $dispatchItem->forceFill([
                'financial_type' => $line['type'],
                'gross_total' => $line['gross'],
                'pizza_base_total' => $line['pizza_base'],
                'extras_total' => $line['extras'],
                'other_total' => $line['other'],
                'discount_total' => $line['discount'],
                'net_total' => $line['net'],
            ])->save();
        }
        $dispatch->forceFill([
            'gross_subtotal' => $snapshot['gross'],
            'pizza_base_subtotal' => $snapshot['pizza_base'],
            'extras_subtotal' => $snapshot['extras'],
            'other_subtotal' => $snapshot['other'],
            'discount_percentage' => $snapshot['discount_percentage'],
            'discount_total' => $snapshot['discount'],
            'total' => $snapshot['total'],
            'financial_snapshot' => $this->snapshot($snapshot),
        ])->save();

        return $dispatch->refresh();
    }

    public function applyToOrder(Order $order, int|string|null $percentage): Order
    {
        $order->load(['items.sections', 'kitchenDispatches.items.orderItem.sections']);
        $snapshot = $this->preview($order->items, $percentage);
        foreach ($order->kitchenDispatches as $dispatch) {
            $dispatchLines = $dispatch->items->map(function ($dispatchItem) use ($snapshot): OrderItem {
                $line = $snapshot['lines'][$dispatchItem->order_item_id];
                $dispatchItem->forceFill([
                    'financial_type' => $line['type'], 'gross_total' => $line['gross'],
                    'pizza_base_total' => $line['pizza_base'], 'extras_total' => $line['extras'],
                    'other_total' => $line['other'], 'discount_total' => $line['discount'], 'net_total' => $line['net'],
                ])->save();

                return $dispatchItem->orderItem;
            });
            $dispatchSnapshot = $this->preview($dispatchLines, $percentage);
            $dispatch->forceFill([
                'gross_subtotal' => $dispatchSnapshot['gross'], 'pizza_base_subtotal' => $dispatchSnapshot['pizza_base'],
                'extras_subtotal' => $dispatchSnapshot['extras'], 'other_subtotal' => $dispatchSnapshot['other'],
                'discount_percentage' => $dispatchSnapshot['discount_percentage'],
                'discount_total' => $this->sum($dispatch->items()->get(), 'discount_total'),
                'total' => $this->sum($dispatch->items()->get(), 'net_total'),
                'financial_snapshot' => $this->snapshot($dispatchSnapshot),
            ])->save();
        }

        return $this->fillOrder($order, $snapshot);
    }

    public function recalculateOrder(Order $order): Order
    {
        $order->load('items.sections');
        $snapshot = $this->preview($order->items);
        $discount = $this->sum($order->kitchenDispatches()->where('status', '!=', 'cancelled')->get(), 'discount_total');
        $snapshot['discount'] = $this->money($discount);
        $snapshot['total'] = $this->money(BigDecimal::of($snapshot['gross'])->minus($discount));

        return $this->fillOrder($order, $snapshot, false);
    }

    private function line(OrderItem $item): array
    {
        $gross = BigDecimal::of($item->line_total)->toScale(2, RoundingMode::HalfUp);
        if ($item->promotion_id || ($item->configuration_snapshot['type'] ?? null) === 'promotion') {
            return $this->lineValues('promotion', $gross, BigDecimal::zero(), BigDecimal::zero(), $gross);
        }
        if (($item->configuration_snapshot['type'] ?? null) === 'standalone_extra') {
            return $this->lineValues('extra', $gross, BigDecimal::zero(), $gross, BigDecimal::zero());
        }
        $item->loadMissing('sections');
        if ($item->sections->isNotEmpty()) {
            $baseUnit = $item->sections->reduce(function (BigDecimal $highest, $section): BigDecimal {
                $candidate = BigDecimal::of($section->unit_price_snapshot);

                return $candidate->isGreaterThan($highest) ? $candidate : $highest;
            }, BigDecimal::zero());
            $base = $baseUnit->multipliedBy($item->quantity)->toScale(2, RoundingMode::HalfUp);
            $extras = $gross->minus($base);

            return $this->lineValues('pizza', $gross, $base, $extras, BigDecimal::zero());
        }

        return $this->lineValues('other', $gross, BigDecimal::zero(), BigDecimal::zero(), $gross);
    }

    private function lineValues(string $type, BigDecimal $gross, BigDecimal $base, BigDecimal $extras, BigDecimal $other): array
    {
        return ['type' => $type, 'gross' => $this->money($gross), 'pizza_base' => $this->money($base), 'extras' => $this->money($extras), 'other' => $this->money($other), 'discount' => '0.00', 'net' => $this->money($gross)];
    }

    private function percentage(int|string|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero()->toScale(2);
        }
        $percentage = BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
        if ($percentage->isLessThanOrEqualTo(0) || $percentage->isGreaterThan('100')) {
            throw new DomainException('El porcentaje de descuento debe ser mayor que 0 y menor o igual a 100.');
        }

        return $percentage;
    }

    private function fillOrder(Order $order, array $snapshot, bool $keepPercentage = true): Order
    {
        $order->forceFill([
            'subtotal' => $snapshot['gross'], 'pizza_base_subtotal' => $snapshot['pizza_base'],
            'extras_subtotal' => $snapshot['extras'], 'other_subtotal' => $snapshot['other'],
            'discount_percentage' => $keepPercentage ? $snapshot['discount_percentage'] : $order->discount_percentage,
            'discount_total' => $snapshot['discount'], 'total' => $snapshot['total'],
            'financial_snapshot' => $this->snapshot($snapshot),
        ])->save();

        return $order->refresh();
    }

    private function snapshot(array $values): array
    {
        return ['version' => 1, 'gross_subtotal' => $values['gross'], 'pizza_base_subtotal' => $values['pizza_base'], 'extras_subtotal' => $values['extras'], 'other_subtotal' => $values['other'], 'discount_percentage' => $values['discount_percentage'], 'discount_total' => $values['discount'], 'total' => $values['total']];
    }

    private function sum(iterable $rows, string $key): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $value = is_array($row) ? $row[$key] : $row->{$key};
            $total = $total->plus($value);
        }

        return $total->toScale(2, RoundingMode::HalfUp);
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
