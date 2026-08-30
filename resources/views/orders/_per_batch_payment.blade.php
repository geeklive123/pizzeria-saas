@if($isPerBatch && $pendingDispatch)
<section class="border-t border-orange-200 bg-orange-50 p-5" data-per-batch-payment>
    <h3 class="text-sm font-bold uppercase tracking-wide text-orange-900">TANDA #{{ $pendingDispatch->sequence_number }} PENDIENTE DE PAGO</h3>
    <div class="mt-3 space-y-1 text-sm">
        @foreach($pendingDispatch->items as $dispatchItem)
            <p class="flex justify-between gap-3"><span>{{ $dispatchItem->orderItem->displayName() }}</span><strong>{{ \App\Support\UiFormatter::money($dispatchItem->gross_total) }}</strong></p>
        @endforeach
    </div>
    <div class="mt-4 space-y-2 border-t border-orange-200 pt-3 text-sm">
        <p class="flex justify-between"><span>Subtotal</span><strong>{{ \App\Support\UiFormatter::money($pendingDispatch->gross_subtotal) }}</strong></p>
        <p class="flex justify-between text-red-700"><span>Descuento</span><strong>-{{ \App\Support\UiFormatter::money($pendingDispatch->discount_total) }}</strong></p>
        <p class="flex justify-between text-lg"><span>TOTAL</span><strong>{{ \App\Support\UiFormatter::money($pendingDispatch->total) }}</strong></p>
        <p class="flex justify-between"><span>Total pagado</span><strong>{{ \App\Support\UiFormatter::money($pendingPaid) }}</strong></p>
        <p class="flex justify-between font-semibold"><span>Saldo</span><strong>{{ \App\Support\UiFormatter::money($pendingBalance) }}</strong></p>
    </div>

    @if(!$cashSession)
        <div class="mt-4 rounded-xl bg-amber-100 p-3 text-sm text-amber-900">Debes abrir tu turno de caja antes de cobrar. <a class="link" href="{{ route('cash.open.form') }}">Abrir caja</a></div>
    @else
        @can('create', $paymentClass)
        <div class="mt-5" data-payment-selector>
            <h4 class="font-semibold">FORMA DE PAGO</h4>
            <div class="mt-3 grid grid-cols-3 gap-2">
                <button class="btn-secondary" type="button" data-payment-method="cash">EFECTIVO</button>
                <button class="btn-secondary" type="button" data-payment-method="qr">QR</button>
                <button class="btn-secondary" type="button" data-payment-method="mixed">MIXTO</button>
            </div>
            <form class="mt-3 rounded-xl border bg-white p-3" method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-payment-form="cash" data-cash-payment hidden>
                @csrf<input type="hidden" name="method" value="cash"><input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}"><input type="hidden" name="idempotency_key" value="{{ $idempotencyCash }}">
                <label class="block"><span class="label">Monto aplicado</span><input class="input" name="amount" value="{{ $pendingBalance }}" max="{{ $pendingBalance }}" inputmode="decimal" required></label>
                <label class="mt-2 block"><span class="label">Efectivo recibido</span><input class="input" name="received_amount" value="{{ $pendingBalance }}" inputmode="decimal" required></label>
                <div class="mt-2 rounded-xl bg-emerald-50 p-3"><span class="text-sm">Vuelto</span><strong class="block text-xl" data-cash-change>Bs 0,00</strong></div>
                <button class="btn-primary mt-3 w-full">Confirmar efectivo</button>
            </form>
            <form class="mt-3 rounded-xl border bg-white p-3" method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-payment-form="qr" hidden>
                @csrf<input type="hidden" name="method" value="qr"><input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}"><input type="hidden" name="idempotency_key" value="{{ $idempotencyQr }}">
                <label class="block"><span class="label">Monto QR</span><input class="input" name="amount" value="{{ $pendingBalance }}" max="{{ $pendingBalance }}" inputmode="decimal" required></label>
                <label class="mt-2 block"><span class="label">Referencia opcional</span><input class="input" name="reference"></label>
                <button class="btn-primary mt-3 w-full">Confirmar QR</button>
            </form>
            <div class="mt-3 rounded-xl border bg-white p-3" data-payment-form="mixed" hidden>
                <p class="text-sm text-stone-600">Registra el efectivo parcial; al volver al panel completa el saldo restante con QR.</p>
                <form class="mt-3" method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-cash-payment>
                    @csrf<input type="hidden" name="method" value="cash"><input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}"><input type="hidden" name="idempotency_key" value="{{ $idempotencyMixedCash }}">
                    <label class="block"><span class="label">Efectivo parcial</span><input class="input" name="amount" max="{{ $pendingBalance }}" inputmode="decimal" required></label>
                    <label class="mt-2 block"><span class="label">Monto recibido</span><input class="input" name="received_amount" inputmode="decimal" required></label>
                    <div class="mt-2 rounded-xl bg-emerald-50 p-3"><span class="text-sm">Vuelto</span><strong class="block text-xl" data-cash-change>Bs 0,00</strong></div>
                    <button class="btn-primary mt-3 w-full">Registrar efectivo parcial</button>
                </form>
                <form class="mt-4 border-t pt-4" method="POST" action="{{ route('orders.payments.store', $order->ulid) }}">
                    @csrf<input type="hidden" name="method" value="qr"><input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}"><input type="hidden" name="idempotency_key" value="{{ $idempotencyMixedQr }}">
                    <label class="block"><span class="label">QR parcial</span><input class="input" name="amount" value="{{ $pendingBalance }}" max="{{ $pendingBalance }}" inputmode="decimal" required></label>
                    <label class="mt-2 block"><span class="label">Referencia opcional</span><input class="input" name="reference"></label>
                    <button class="btn-primary mt-3 w-full">Completar saldo con QR</button>
                </form>
            </div>
        </div>
        @endcan
    @endif
</section>
<script>
document.querySelectorAll('[data-payment-selector]').forEach((selector) => {
    selector.querySelectorAll('[data-payment-method]').forEach((button) => {
        button.addEventListener('click', () => {
            selector.querySelectorAll('[data-payment-form]').forEach((form) => {
                form.hidden = form.dataset.paymentForm !== button.dataset.paymentMethod;
            });
        });
    });
});
document.querySelectorAll('[data-cash-payment]').forEach((form) => {
    const amount = form.querySelector('[name="amount"]');
    const received = form.querySelector('[name="received_amount"]');
    const output = form.querySelector('[data-cash-change]');
    const cents = (value) => {
        const parts = String(value || '').replace(',', '.').split('.');

        return (Number.parseInt(parts[0] || '0', 10) * 100) + Number.parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
    };
    const update = () => {
        const change = Math.max(0, cents(received.value) - cents(amount.value));
        output.textContent = 'Bs ' + Math.floor(change / 100) + ',' + String(change % 100).padStart(2, '0');
    };
    amount.addEventListener('input', update);
    received.addEventListener('input', update);
    update();
});
</script>
@endif
