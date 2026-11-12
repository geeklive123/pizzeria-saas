<?php

namespace App\Printing\Renderers;

use App\Enums\ModifierOptionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Printing\EscPosDocumentBuilder;
use App\Printing\ThermalDocument;
use App\Support\UiFormatter;
use DateTimeZone;

class KitchenCommandRenderer
{
    public function render(KitchenDispatch $dispatch, bool $reprint = false): ThermalDocument
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
            ->line('PEDIDO '.$order->formattedOperationalNumber())->bold(false)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Usuario: '.($dispatch->dispatchedBy?->name ?? '—'));

        if ($order->customer_name) {
            $builder->line('Cliente: '.$order->customer_name);
        }
        if ($order->customer_phone) {
            $builder->line('Tel: '.$order->customer_phone);
        }
        if ($order->notes) {
            $builder->line()->bold()->line('OBSERVACIONES DEL PEDIDO:')->bold(false)->line($order->notes)->line();
        }

        foreach ($dispatch->items as $dispatchItem) {
            $item = $dispatchItem->orderItem;
            if ($order->type === OrderType::Takeaway && $item->status === OrderItemStatus::Cancelled) {
                continue;
            }
            $builder->line(str_repeat('-', 48))->bold()->line($this->itemTitle($item))->bold(false);
            $this->itemNotes($builder, $item);

            if ($item->sections->isNotEmpty()) {
                foreach ($item->sections as $section) {
                    $fraction = $item->sections->count() > 1 ? $section->fractionLabel().' ' : '';
                    $builder->line('    '.$fraction.mb_strtoupper($section->product_name_snapshot));
                }
            }
            $this->promotionDetails($builder, $item);

            $builder->line()->bold()->line($item->fulfillment_type === OrderType::Takeaway ? '    PARA LLEVAR' : '    COMER AQUÍ')->bold(false);
            $this->modifiers($builder, $item, ModifierOptionType::Add, 'EXTRAS:', '+ ');
            $this->modifiers($builder, $item, ModifierOptionType::Remove, 'QUITAR:', '- ');
        }

        $footer = $reprint
            ? 'REIMPRESION · TANDA #'.$dispatch->sequence_number
            : 'NUEVA COMANDA · TANDA #'.$dispatch->sequence_number;

        return $builder->line(str_repeat('-', 48))->alignCenter()->bold()->line($footer)->bold(false)->finish();
    }

    public function renderOrder(Order $order, User $requestedBy): ThermalDocument
    {
        $order->loadMissing([
            'restaurantTable',
            'items.productVariant.product',
            'items.sections',
            'items.modifiers',
        ]);
        $date = ($order->closed_at ?? $order->opened_at ?? now())
            ->setTimezone(new DateTimeZone('America/La_Paz'));
        $builder = (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('MASA & MAÑA')->doubleSize(false)->bold(false)->line()
            ->bold()->doubleSize()->line($order->restaurantTable?->name ?? 'PARA LLEVAR')->doubleSize(false)
            ->line('PEDIDO '.$order->formattedOperationalNumber())->bold(false)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Usuario: '.$requestedBy->name);

        if ($order->customer_name) {
            $builder->line('Cliente: '.$order->customer_name);
        }
        if ($order->customer_phone) {
            $builder->line('Tel: '.$order->customer_phone);
        }
        if ($order->notes) {
            $builder->line()->bold()->line('OBSERVACIONES DEL PEDIDO:')->bold(false)->line($order->notes)->line();
        }

        foreach ($order->items->where('status', '!=', OrderItemStatus::Cancelled) as $item) {
            $builder->line(str_repeat('-', 48))->bold()->line($this->itemTitle($item))->bold(false);
            $this->itemNotes($builder, $item);

            if ($item->sections->isNotEmpty()) {
                foreach ($item->sections as $section) {
                    $fraction = $item->sections->count() > 1 ? $section->fractionLabel().' ' : '';
                    $builder->line('    '.$fraction.mb_strtoupper($section->product_name_snapshot));
                }
            }
            $this->promotionDetails($builder, $item);

            $builder->line()->bold()->line($item->fulfillment_type === OrderType::Takeaway ? '    PARA LLEVAR' : '    COMER AQUÍ')->bold(false);
            $this->modifiers($builder, $item, ModifierOptionType::Add, 'EXTRAS:', '+ ');
            $this->modifiers($builder, $item, ModifierOptionType::Remove, 'QUITAR:', '- ');
        }

        return $builder->line(str_repeat('-', 48))->alignCenter()->bold()
            ->line('REIMPRESION HISTORICA · PEDIDO COMPLETO')->bold(false)->finish();
    }

    private function itemNotes(EscPosDocumentBuilder $builder, OrderItem $item): void
    {
        if (! $item->notes) {
            return;
        }

        // El agente transmite RAW y no anuncia capacidades: no se usa vídeo inverso.
        // 16 columnas en doble ancho caben incluso en una impresora de 58 mm.
        $notes = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $item->notes));
        $builder->line()->bold()->doubleSize()->line('!! OBSERVACIONES !!');
        foreach (explode("\n", $notes ?? '') as $line) {
            if ($line === '') {
                $builder->line();

                continue;
            }
            foreach (mb_str_split($line, 16) as $part) {
                $builder->line($part);
            }
        }
        $builder->doubleSize(false)->bold(false)->line();
    }

    private function itemTitle(OrderItem $item): string
    {
        $quantity = UiFormatter::inputQuantity($item->quantity);
        if ($item->sections->isNotEmpty()) {
            return $quantity.' x PIZZA '.mb_strtoupper(UiFormatter::variantName($item->sections->first()->variant_name_snapshot, $item->configuration_snapshot['size_key'] ?? null));
        }

        if (($item->configuration_snapshot['type'] ?? null) === 'promotion') {
            return $quantity.' x '.mb_strtoupper($item->displayName());
        }

        if (($item->configuration_snapshot['type'] ?? null) === 'standalone_extra') {
            return $quantity.' x EXTRA '.mb_strtoupper($item->displayName());
        }

        $product = mb_strtoupper($item->productVariant->product->name);
        $variant = mb_strtoupper(UiFormatter::variantName($item->productVariant->name, $item->productVariant->size_key));

        return $quantity.' x '.$product.($variant !== $product ? ' '.$variant : '');
    }

    private function modifiers(EscPosDocumentBuilder $builder, OrderItem $item, ModifierOptionType $type, string $heading, string $prefix): void
    {
        if (($item->configuration_snapshot['type'] ?? null) === 'standalone_extra') {
            return;
        }

        $modifiers = $item->modifiers->where('type', $type);
        if ($modifiers->isEmpty()) {
            return;
        }

        $builder->line()->bold()->line($heading)->bold(false);
        foreach ($modifiers as $modifier) {
            $builder->line($prefix.mb_strtoupper($modifier->name_snapshot));
        }
    }

    private function promotionDetails(EscPosDocumentBuilder $builder, OrderItem $item): void
    {
        if (($item->configuration_snapshot['type'] ?? null) !== 'promotion'
            || ($item->configuration_snapshot['pizzas'] ?? []) === []) {
            return;
        }
        foreach ($item->configuration_snapshot['pizzas'] ?? [] as $index => $pizza) {
            $builder->line()->bold()->line('PIZZA '.($index + 1).' · '.$this->sizeLabel($pizza['size_key'] ?? null))->bold(false);
            foreach ($pizza['sections'] ?? [] as $section) {
                $fraction = count($pizza['sections'] ?? []) > 1 ? ($section['fraction'] ?? '').' ' : '';
                $builder->line('    '.$fraction.mb_strtoupper($section['product'] ?? ''));
            }
            foreach ($pizza['modifiers'] ?? [] as $modifier) {
                $prefix = ($modifier['type'] ?? null) === 'remove' ? '- ' : '+ ';
                $builder->line('    '.$prefix.mb_strtoupper($modifier['name'] ?? ''));
            }
        }
        foreach ($item->configuration_snapshot['components'] ?? [] as $component) {
            $builder->line('INCLUYE: '.mb_strtoupper($component['inventory_item_name'] ?? '').' x '.UiFormatter::inputQuantity($component['quantity_applied'] ?? '0'));
        }
    }

    private function sizeLabel(?string $sizeKey): string
    {
        return $sizeKey === 'familiar' ? 'GRANDE' : mb_strtoupper((string) $sizeKey);
    }
}
