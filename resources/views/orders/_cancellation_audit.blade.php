@php
    $paidCancellationAudit = $order->cancellationAudits
        ->where('scope', \App\Enums\OrderCancellationScope::PaidOrder)
        ->sortByDesc('id')
        ->first();
@endphp
@if ($order->status === \App\Enums\OrderStatus::Cancelled || $paidCancellationAudit)
    <div class="space-y-3">
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
            <p class="font-bold">ANULADO</p>
            <p>Motivo: {{ $paidCancellationAudit?->cancellation_reason ?? $order->cancellation_reason }}</p>
            <p>Anulado por: {{ $paidCancellationAudit?->cancelledBy?->name ?? $order->cancelledBy?->name ?? 'Sistema' }}</p>
            <p>Fecha: {{ \App\Support\UiFormatter::date($paidCancellationAudit?->cancelled_at ?? $order->cancelled_at, true) }}</p>
        </div>

        @if($paidCancellationAudit?->restored_at)
            <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">
                <p class="font-bold">ANULACIÓN REVERTIDA</p>
                <p>Motivo: {{ $paidCancellationAudit->restoration_reason }}</p>
                <p>Restaurado por: {{ $paidCancellationAudit->restoredBy?->name ?? 'Sistema' }}</p>
                <p>Fecha: {{ \App\Support\UiFormatter::date($paidCancellationAudit->restored_at, true) }}</p>
            </div>
        @elseif($order->status === \App\Enums\OrderStatus::Cancelled)
            @if(! $paidCancellationAudit)
                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    Datos legacy sin auditoría suficiente. Esta venta no puede restaurarse automáticamente.
                </div>
            @else
                @can('restoreCancellation', $order)
                    <details class="rounded-lg border border-emerald-200 p-3 text-sm">
                        <summary class="cursor-pointer font-bold text-emerald-800">Deshacer anulación</summary>
                        <form method="POST" action="{{ route('orders.restore-paid', $order->ulid) }}" class="mt-3 space-y-3">
                            @csrf
                            <p>Esta operación restaurará la venta, sus productos, inventario y movimientos económicos relacionados.</p>
                            <label class="block"><span class="label">Motivo obligatorio</span><textarea class="input" name="reason" maxlength="500" required></textarea></label>
                            <label class="flex items-start gap-2"><input type="checkbox" name="confirmed" value="1" required><span>Confirmo que esta venta fue anulada por error.</span></label>
                            <button class="btn-primary" type="submit">Restaurar venta</button>
                        </form>
                    </details>
                @endcan
            @endif
        @endif
    </div>
@endif
