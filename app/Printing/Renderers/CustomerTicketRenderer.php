<?php

namespace App\Printing\Renderers;

use App\Enums\OrderItemStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
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
        $order->loadMissing([
            'restaurantTable', 'items.productVariant.product', 'items.sections',
            'payments.receivedBy',
        ]);
        $completedPayments = $order->payments->where('status', PaymentStatus::Completed)->sortBy('paid_at')->values();
        $cashier = $completedPayments->last()?->receivedBy?->name ?? $requestedBy->name;
        $date = ($completedPayments->last()?->paid_at ?? now())->setTimezone(new DateTimeZone('America/La_Paz'));
        $builder = (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('MASA & MAÑA')->doubleSize(false)->bold(false)->line()
            ->bold()->line('PEDIDO '.$order->formattedNumber())->line($order->restaurantTable?->name ?? 'PARA LLEVAR')->bold(false)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Cajera: '.$cashier);

        if ($order->customer_name) {
            $builder->line('Cliente: '.$order->customer_name);
        }

        $builder->line(str_repeat('-', 48));
        foreach ($order->items->where('status', '!=', OrderItemStatus::Cancelled) as $item) {
            $builder->line($this->amountLine($this->itemName($item), $item->line_total));
        }
        $builder->line(str_repeat('-', 48))->line()
            ->line($this->amountLine('SUBTOTAL', $order->subtotal))
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
            ->line()->alignCenter()->line('Gracias por su preferencia')->finish();
    }

    private function itemName(OrderItem $item): string
    {
        $quantity = UiFormatter::inputQuantity($item->quantity);
        if ($item->sections->isNotEmpty()) {
            $flavors = $item->sections->pluck('product_name_snapshot')->join('/');
            $name = $flavors.' '.$item->sections->first()->variant_name_snapshot;
        } else {
            $product = $item->productVariant->product->name;
            $variant = $item->productVariant->name;
            $name = $product.($variant !== $product ? ' '.$variant : '');
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
