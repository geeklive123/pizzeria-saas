@if ($order->status === \App\Enums\OrderStatus::Paid)
    @can('cancelPaid', $order)
        <details class="rounded-lg border border-red-200 p-2 text-sm">
            <summary class="cursor-pointer font-bold text-red-700">ANULAR Y REVERTIR</summary>
            <form method="POST" action="{{ route('orders.cancel-paid', $order->ulid) }}" class="mt-3 space-y-3">
                @csrf
                <p>Esta acción revertirá los pagos y el inventario del pedido. El historial se conservará.</p>
                <p class="text-xs text-slate-600">Todos los turnos de caja de sus pagos deben seguir abiertos.</p>
                <label class="block"><span class="label">Motivo de anulación</span><textarea class="input" name="reason" maxlength="500" required></textarea></label>
                <label class="flex items-start gap-2"><input type="checkbox" required><span>Confirmo la anulación y reversión del pedido {{ $order->formattedOperationalNumber() }}.</span></label>
                <button class="btn-danger" type="submit">Confirmar ANULAR Y REVERTIR</button>
            </form>
        </details>
    @endcan
@endif
