<?php

namespace App\Services;

use App\Enums\KitchenDispatchStatus;
use App\Enums\ModifierOptionType;
use App\Enums\OrderItemStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\UiFormatter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

class OrderHistoryService
{
    public function __construct(
        private readonly OrderFinancialService $financials,
        private readonly OrderPaymentService $payments,
    ) {}

    public function forOrder(Order $order): array
    {
        $order->loadMissing([
            'items.productVariant.product',
            'items.sections.productVariant.product',
            'items.modifiers.section',
            'kitchenDispatches.items.orderItem.productVariant.product',
            'kitchenDispatches.items.orderItem.sections.productVariant.product',
            'kitchenDispatches.items.orderItem.modifiers.section',
            'kitchenDispatches.payments',
        ]);

        $batches = $order->kitchenDispatches
            ->sortBy('sequence_number')
            ->map(fn ($dispatch): array => [
                'sequence' => $dispatch->sequence_number,
                'time' => $dispatch->dispatched_at,
                'total' => $dispatch->total,
                'balance' => $this->payments->dispatchBalance($dispatch),
                'status' => $this->status($dispatch->status),
                'payment' => $this->paymentSummary($dispatch->payments),
                'items' => $dispatch->items->sortBy('id')->map(
                    fn ($dispatchItem): array => $this->item(
                        $dispatchItem->orderItem,
                        $dispatchItem->net_total,
                    ),
                )->values()->all(),
                'is_current' => false,
            ])
            ->values();

        $draftItems = $order->items->where('status', OrderItemStatus::Draft)->values();
        if ($draftItems->isNotEmpty()) {
            $draft = $this->financials->preview($draftItems);
            $batches->push([
                'sequence' => ((int) $order->kitchenDispatches->max('sequence_number')) + 1,
                'time' => null,
                'total' => $draft['total'],
                'balance' => $draft['total'],
                'status' => [
                    'key' => 'current',
                    'label' => 'ACTUAL / SIN ENVIAR',
                    'classes' => 'bg-orange-100 text-orange-700',
                ],
                'payment' => null,
                'items' => $draftItems->map(
                    fn (OrderItem $item): array => $this->item($item, $draft['lines'][$item->id]['net']),
                )->all(),
                'is_current' => true,
            ]);
        }

        return [
            'batches' => $batches->all(),
            'summary' => [
                'total_batches' => $batches->count(),
                'paid_batches' => $order->kitchenDispatches->where('status', KitchenDispatchStatus::Settled)->count(),
                'pending_batches' => $order->kitchenDispatches
                    ->whereIn('status', [KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released])
                    ->count(),
                'total' => $order->total,
                'paid' => $this->payments->paid($order),
                'balance' => $this->payments->balance($order),
            ],
        ];
    }

    private function status(KitchenDispatchStatus $status): array
    {
        return match ($status) {
            KitchenDispatchStatus::Settled => [
                'key' => 'paid',
                'label' => 'PAGADA',
                'classes' => 'bg-emerald-100 text-emerald-700',
            ],
            KitchenDispatchStatus::Cancelled => [
                'key' => 'cancelled',
                'label' => 'CANCELADA',
                'classes' => 'bg-red-100 text-red-700',
            ],
            KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released => [
                'key' => 'pending',
                'label' => 'PENDIENTE DE PAGO',
                'classes' => 'bg-amber-100 text-amber-700',
            ],
        };
    }

    private function paymentSummary(Collection $payments): ?array
    {
        $completed = $payments->where('status', PaymentStatus::Completed);
        if ($completed->isEmpty()) {
            return null;
        }

        $methods = $completed
            ->groupBy(fn (Payment $payment): string => $payment->method->value)
            ->map(function (Collection $methodPayments): array {
                $total = $methodPayments->reduce(
                    fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($payment->amount),
                    BigDecimal::zero(),
                );

                return [
                    'label' => UiFormatter::paymentMethod($methodPayments->first()->method),
                    'amount' => (string) $total->toScale(2, RoundingMode::HalfUp),
                ];
            })
            ->values();

        return [
            'label' => $methods->count() > 1 ? 'Mixto' : $methods->first()['label'],
            'methods' => $methods->all(),
        ];
    }

    private function item(OrderItem $item, int|string $total): array
    {
        return [
            'name' => $item->displayName(),
            'quantity' => $item->quantity,
            'total' => $total,
            'flavors' => $item->sections->pluck('product_name_snapshot')->filter()->values()->all(),
            'extras' => $item->modifiers
                ->where('type', ModifierOptionType::Add)
                ->pluck('name_snapshot')
                ->filter()
                ->values()
                ->all(),
            'notes' => $item->notes,
        ];
    }
}
