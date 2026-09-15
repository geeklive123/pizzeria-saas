<?php

namespace App\Printing\Renderers;

use App\Enums\ModifierOptionType;
use App\Enums\OrderItemStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Printing\EscPosDocumentBuilder;
use App\Printing\ThermalDocument;
use App\Services\OrderPaymentService;
use App\Support\UiFormatter;
use DateTimeZone;

class CustomerTicketRenderer
{
    public function __construct(private readonly OrderPaymentService $payments) {}

    public function render(Order $order, User $requestedBy): ThermalDocument
    {
        return $this->renderDocument($order, $requestedBy, false);
    }

    public function renderProvisional(Order $order, User $requestedBy): ThermalDocument
    {
        return $this->renderDocument($order, $requestedBy, true);
    }

    private function renderDocument(Order $order, User $requestedBy, bool $provisional): ThermalDocument
    {
        $order->loadMissing([
            'restaurantTable', 'items.productVariant.product', 'items.sections', 'items.modifiers',
            'payments.receivedBy',
        ]);
        $completedPayments = $order->payments->where('status', PaymentStatus::Completed)->sortBy('paid_at')->values();
        $cashier = $completedPayments->last()?->receivedBy?->name ?? $requestedBy->name;
        $date = ($completedPayments->last()?->paid_at ?? now())->setTimezone(new DateTimeZone('America/La_Paz'));
        $builder = (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('MASA & MAÑA')->doubleSize(false)->bold(false)->line()
            ->bold()->line($provisional ? 'CUENTA PROVISIONAL' : 'PEDIDO '.$order->formattedOperationalNumber());
        if ($provisional) {
            $builder->line('PEDIDO '.$order->formattedOperationalNumber());
        }
        $builder->line($order->restaurantTable?->name ?? 'PARA LLEVAR')->bold(false)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Cajera: '.$cashier);

        if ($order->customer_name) {
            $builder->line('Cliente: '.$order->customer_name);
        }

        $builder->line(str_repeat('-', 48));
        foreach ($order->items->where('status', '!=', OrderItemStatus::Cancelled) as $item) {
            $builder->line($this->amountLine($this->itemName($item), $item->line_total));
            $this->extras($builder, $item);
        }
        $builder->line(str_repeat('-', 48))->line()
            ->line($this->amountLine('SUBTOTAL', $order->subtotal))
            ->line($this->amountLine('PIZZA BASE', $order->pizza_base_subtotal))
            ->line($this->amountLine('TOPPINGS / EXTRAS', $order->extras_subtotal))
            ->line($this->amountLine('OTROS', $order->other_subtotal))
            ->line($this->amountLine('DESCUENTO', $order->discount_total))
            ->bold()->line($this->amountLine('TOTAL', $order->total))->bold(false)->line();

        if ($completedPayments->isNotEmpty()) {
            if ($completedPayments->count() > 1) {
                $builder->bold()->line('PAGOS')->bold(false);
            }
            foreach ($completedPayments as $payment) {
                $label = UiFormatter::paymentMethod($payment->method);
                $builder->line($this->amountLine(mb_strtoupper($label), $payment->amount));
                if ($payment->method === PaymentMethod::Cash && $payment->received_amount !== null) {
                    $builder->line($this->amountLine('RECIBIDO', $payment->received_amount));
                    $builder->line($this->amountLine('CAMBIO', $payment->change_amount));
                }
            }
            $builder->line();
        }

        return $builder
            ->bold()->line($this->amountLine('PAGADO', $this->payments->paid($order)))
            ->line($this->amountLine('SALDO', $this->payments->balance($order)))->bold(false)
            ->line()->alignCenter()->line($provisional ? 'CUENTA PROVISIONAL - NO ES PAGO' : 'Gracias por su preferencia')->finish();
    }

    public function renderDispatch(KitchenDispatch $dispatch, User $requestedBy): ThermalDocument
    {
        $dispatch->loadMissing(['order.restaurantTable', 'items.orderItem.productVariant.product', 'items.orderItem.sections', 'items.orderItem.modifiers', 'payments.receivedBy']);
        $order = $dispatch->order;
        $payments = $dispatch->payments->where('status', PaymentStatus::Completed)->sortBy('paid_at')->values();
        $date = ($payments->last()?->paid_at ?? $dispatch->settled_at ?? now())->setTimezone(new DateTimeZone('America/La_Paz'));
        $builder = (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('MASA & MAÑA')->doubleSize(false)->bold(false)->line()
            ->bold()->line('PEDIDO '.$order->formattedOperationalNumber().' · TANDA #'.$dispatch->sequence_number)
            ->line($order->restaurantTable?->name ?? 'PARA LLEVAR')->bold(false)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Cajera: '.($payments->last()?->receivedBy?->name ?? $requestedBy->name));
        if ($order->customer_name) {
            $builder->line('Cliente: '.$order->customer_name);
        }
        $builder->line(str_repeat('-', 48));
        foreach ($dispatch->items as $dispatchItem) {
            $builder->line($this->amountLine($this->itemName($dispatchItem->orderItem), $dispatchItem->gross_total));
            $this->extras($builder, $dispatchItem->orderItem);
        }
        $builder->line(str_repeat('-', 48))->line()
            ->line($this->amountLine('SUBTOTAL', $dispatch->gross_subtotal))
            ->line($this->amountLine('PIZZA BASE', $dispatch->pizza_base_subtotal))
            ->line($this->amountLine('TOPPINGS / EXTRAS', $dispatch->extras_subtotal))
            ->line($this->amountLine('OTROS', $dispatch->other_subtotal))
            ->line($this->amountLine('DESCUENTO PIZZAS', $dispatch->discount_total))
            ->bold()->line($this->amountLine('TOTAL', $dispatch->total))->bold(false)->line();
        foreach ($payments as $payment) {
            $builder->line($this->amountLine(mb_strtoupper(UiFormatter::paymentMethod($payment->method)), $payment->amount));
        }

        return $builder->line()->alignCenter()->line('Gracias por su preferencia')->finish();
    }

    private function extras(EscPosDocumentBuilder $builder, OrderItem $item): void
    {
        if (($item->configuration_snapshot['type'] ?? null) === 'standalone_extra') {
            return;
        }

        foreach ($item->modifiers->where('type', ModifierOptionType::Add) as $modifier) {
            $builder->line('  + '.mb_strtoupper($modifier->name_snapshot).'  Bs '.UiFormatter::decimal($modifier->price_delta_snapshot, 2));
        }
    }

    private function itemName(OrderItem $item): string
    {
        $quantity = UiFormatter::inputQuantity($item->quantity);
        if ($item->sections->isNotEmpty()) {
            $flavors = $item->sections->pluck('product_name_snapshot')->join('/');
            $name = $flavors.' '.UiFormatter::variantName($item->sections->first()->variant_name_snapshot, $item->configuration_snapshot['size_key'] ?? null);
        } elseif (($item->configuration_snapshot['type'] ?? null) === 'standalone_extra') {
            $name = $item->displayName();
        } else {
            if (($item->configuration_snapshot['type'] ?? null) === 'promotion') {
                $name = $item->displayName();
            } else {
                $product = $item->productVariant->product->name;
                $variant = UiFormatter::variantName($item->productVariant->name, $item->productVariant->size_key);
                $name = $product.($variant !== $product ? ' '.$variant : '');
            }
        }

        return $quantity.' '.mb_strtoupper($name);
    }

    private function amountLine(string $label, int|string $amount): string
    {
        $money = 'Bs '.UiFormatter::decimal($amount, 2);
        $available = max(1, 48 - mb_strlen($money) - 1);
        $label = mb_strimwidth($label, 0, $available, '…');

        return $label.str_repeat(' ', max(1, 48 - mb_strlen($label) - mb_strlen($money))).$money;
    }
}
