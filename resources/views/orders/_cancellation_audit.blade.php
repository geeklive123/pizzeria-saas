@if ($order->status === \App\Enums\OrderStatus::Cancelled)
    <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
        <p class="font-bold">ANULADO</p>
        <p>Motivo: {{ $order->cancellation_reason }}</p>
        <p>Anulado por: {{ $order->cancelledBy?->name ?? 'Sistema' }}</p>
        <p>Fecha: {{ \App\Support\UiFormatter::date($order->cancelled_at, true) }}</p>
    </div>
@endif
