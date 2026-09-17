<?php

namespace App\Services;

use App\Enums\KitchenDispatchStatus;
use App\Enums\ModifierOptionType;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
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
            'kitchenDispatches.items.orderItem.cancelledBy',
            'kitchenDispatches.cancelledBy',
            'kitchenDispatches.dispatchedBy',
            'kitchenDispatches.payments',
            'cancellationAudits.cancelledBy',
            'cancellationAudits.restoredBy',
        ]);

        $audits = $order->cancellationAudits->sortByDesc('id');
        $batches = $order->kitchenDispatches
            ->sortBy('sequence_number')
            ->map(function ($dispatch) use ($audits): array {
                $dispatchAudit = $audits->first(
                    fn (OrderCancellationAudit $audit): bool => $audit->scope === OrderCancellationScope::KitchenDispatch
                        && (int) $audit->kitchen_dispatch_id === (int) $dispatch->getKey(),
                );
                $settledReversal = data_get($dispatchAudit?->snapshot, 'reversal_type') === 'settled_dispatch';

                return [
                    'ulid' => $dispatch->ulid,
                    'sequence' => $dispatch->sequence_number,
                    'time' => $dispatch->dispatched_at,
                    'total' => $dispatch->total,
                    'balance' => $this->payments->dispatchBalance($dispatch),
                    'status' => $this->status($dispatch->status, $settledReversal),
                    'payment' => $this->paymentSummary($dispatch->payments),
                    'dispatched_by' => $dispatch->dispatchedBy?->name,
                    'cancelled_at' => $dispatch->cancelled_at,
                    'cancelled_by' => $dispatch->cancelledBy?->name,
                    'cancellation_reason' => $dispatch->cancellation_reason,
                    'cancellation_audit' => $this->audit($dispatchAudit),
                    'items' => $dispatch->items->sortBy('id')->map(
                        fn ($dispatchItem): array => $this->item(
                            $dispatchItem->orderItem,
                            $dispatchItem->net_total,
                            $audits->first(
                                fn (OrderCancellationAudit $audit): bool => $audit->scope === OrderCancellationScope::KitchenDispatchItem
                                    && (int) $audit->order_item_id === (int) $dispatchItem->order_item_id,
                            ),
                        ),
                    )->values()->all(),
                    'is_current' => false,
                    'is_settled_reversal' => $settledReversal,
                ];
            })
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
                'reverted_batches' => $batches->where('is_settled_reversal', true)->count(),
                'pending_batches' => $order->kitchenDispatches
                    ->whereIn('status', [KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released])
                    ->count(),
                'total' => $order->total,
                'paid' => $this->payments->paid($order),
                'reverted' => $batches->where('is_settled_reversal', true)->reduce(
                    fn (BigDecimal $sum, array $batch): BigDecimal => $sum->plus($batch['total']),
                    BigDecimal::zero(),
                )->toScale(2, RoundingMode::HalfUp)->__toString(),
                'balance' => $this->payments->balance($order),
            ],
        ];
    }

    private function status(KitchenDispatchStatus $status, bool $settledReversal = false): array
    {
        if ($status === KitchenDispatchStatus::Cancelled && $settledReversal) {
            return [
                'key' => 'reverted',
                'label' => 'REVERTIDA',
                'classes' => 'bg-red-100 text-red-700',
            ];
        }

        return match ($status) {
            KitchenDispatchStatus::Settled => [
                'key' => 'paid',
                'label' => 'PAGADA',
                'classes' => 'bg-emerald-100 text-emerald-700',
            ],
            KitchenDispatchStatus::Cancelled => [
                'key' => 'cancelled',
                'label' => 'ANULADA',
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

    private function item(OrderItem $item, int|string $total, ?OrderCancellationAudit $audit = null): array
    {
        return [
            'ulid' => $item->ulid,
            'name' => $item->displayName(),
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'original_total' => $item->line_total,
            'total' => $total,
            'status' => $item->status->value,
            'cancelled_at' => $item->cancelled_at,
            'cancelled_by' => $item->cancelledBy?->name,
            'cancellation_reason' => $item->cancellation_reason,
            'cancellation_audit' => $this->audit($audit),
            'flavors' => $item->sections->pluck('product_name_snapshot')->filter()->values()->all(),
            'extras' => ($item->configuration_snapshot['type'] ?? null) === 'standalone_extra' ? [] : $item->modifiers
                ->where('type', ModifierOptionType::Add)
                ->pluck('name_snapshot')
                ->filter()
                ->values()
                ->all(),
            'notes' => $item->notes,
        ];
    }

    private function audit(?OrderCancellationAudit $audit): ?array
    {
        if (! $audit) {
            return null;
        }

        return [
            'ulid' => $audit->ulid,
            'parent_id' => $audit->parent_id,
            'cancelled_at' => $audit->cancelled_at,
            'cancelled_by' => $audit->cancelledBy?->name,
            'cancellation_reason' => $audit->cancellation_reason,
            'restored_at' => $audit->restored_at,
            'restored_by' => $audit->restoredBy?->name,
            'restoration_reason' => $audit->restoration_reason,
        ];
    }
}
