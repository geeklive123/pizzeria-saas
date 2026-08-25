<?php

namespace App\Printing\Renderers;

use App\Enums\ModifierOptionType;
use App\Enums\OrderType;
use App\Models\KitchenDispatch;
use App\Models\OrderItem;
use App\Printing\EscPosDocumentBuilder;
use App\Printing\ThermalDocument;
use App\Support\UiFormatter;
use DateTimeZone;

class KitchenCommandRenderer
{
    public function render(KitchenDispatch $dispatch): ThermalDocument
    {
        $dispatch->loadMissing([
            'order.restaurantTable', 'dispatchedBy',
            'items.orderItem.productVariant.product',
            'items.orderItem.sections', 'items.orderItem.modifiers',
        ]);
        $order = $dispatch->order;
        $date = $dispatch->dispatched_at->setTimezone(new DateTimeZone('America/La_Paz'));
        $builder = (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('MASA & MAÑA')->doubleSize(false)->bold(false)->line()
            ->bold()->doubleSize()->line($order->restaurantTable?->name ?? 'PARA LLEVAR')->doubleSize(false)
            ->line('PEDIDO '.$order->formattedNumber())->bold(false)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Usuario: '.($dispatch->dispatchedBy?->name ?? '—'));

        if ($order->customer_name) {
            $builder->line('Cliente: '.$order->customer_name);
        }
        if ($order->customer_phone) {
            $builder->line('Tel: '.$order->customer_phone);
        }
        if ($order->notes) {
            $builder->line('OBS PEDIDO:')->line(mb_strtoupper($order->notes));
        }

        foreach ($dispatch->items as $dispatchItem) {
            $item = $dispatchItem->orderItem;
            $builder->line(str_repeat('-', 48))->bold()->line($this->itemTitle($item))->bold(false);

            if ($item->sections->isNotEmpty()) {
                foreach ($item->sections as $section) {
                    $fraction = $item->sections->count() > 1 ? $section->fractionLabel().' ' : '';
                    $builder->line('    '.$fraction.mb_strtoupper($section->product_name_snapshot));
                }
            }

            $builder->line()->bold()->line($item->fulfillment_type === OrderType::Takeaway ? '    PARA LLEVAR' : '    COMER AQUÍ')->bold(false);
            $this->modifiers($builder, $item, ModifierOptionType::Add, 'EXTRAS:', '+ ');
            $this->modifiers($builder, $item, ModifierOptionType::Remove, 'QUITAR:', '- ');
            if ($item->notes) {
                $builder->line()->bold()->line('OBS:')->bold(false)->line(mb_strtoupper($item->notes));
            }
        }

        return $builder->line(str_repeat('-', 48))->alignCenter()->bold()->line('NUEVA COMANDA · TANDA #'.$dispatch->sequence_number)->bold(false)->finish();
    }

    private function itemTitle(OrderItem $item): string
    {
        $quantity = UiFormatter::inputQuantity($item->quantity);
        if ($item->sections->isNotEmpty()) {
            return $quantity.' x PIZZA '.mb_strtoupper($item->sections->first()->variant_name_snapshot);
        }

        $product = mb_strtoupper($item->productVariant->product->name);
        $variant = mb_strtoupper($item->productVariant->name);

        return $quantity.' x '.$product.($variant !== $product ? ' '.$variant : '');
    }

    private function modifiers(EscPosDocumentBuilder $builder, OrderItem $item, ModifierOptionType $type, string $heading, string $prefix): void
    {
        $modifiers = $item->modifiers->where('type', $type);
        if ($modifiers->isEmpty()) {
            return;
        }

        $builder->line()->bold()->line($heading)->bold(false);
        foreach ($modifiers as $modifier) {
            $builder->line($prefix.mb_strtoupper($modifier->name_snapshot));
        }
    }
}
