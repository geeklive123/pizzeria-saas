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
        <div class="mt-4" data-payment-selector data-balance="{{ $pendingBalance }}">
            <h4 class="text-sm font-bold">FORMA DE PAGO</h4>
            <div class="mt-2 grid grid-cols-3 gap-2">
                <form method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-quick-payment>
                    @csrf
                    <input type="hidden" name="method" value="cash">
                    <input type="hidden" name="amount" value="{{ $pendingBalance }}">
                    <input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyCash }}">
                    <button class="btn-secondary w-full px-2" data-payment-submit>EFECTIVO</button>
                </form>
                <form method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-quick-payment>
                    @csrf
                    <input type="hidden" name="method" value="qr">
                    <input type="hidden" name="amount" value="{{ $pendingBalance }}">
                    <input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyQr }}">
                    <button class="btn-secondary w-full px-2" data-payment-submit>QR</button>
                </form>
                <button class="btn-secondary px-2" type="button" data-payment-method="mixed">MIXTO</button>
            </div>

            <form class="mt-3 space-y-3 rounded-xl border bg-white p-3" method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-payment-form="mixed" hidden>
                @csrf
                <input type="hidden" name="method" value="mixed">
                <input type="hidden" name="kitchen_dispatch" value="{{ $pendingDispatch->ulid }}">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyMixedCash }}">
                <p class="flex justify-between text-sm"><span>Saldo total</span><strong>{{ \App\Support\UiFormatter::money($pendingBalance) }}</strong></p>
                <label class="block"><span class="label">Efectivo</span><input class="input" name="cash_amount" value="0.00" inputmode="decimal" required data-mixed-cash></label>
                <p class="flex justify-between text-sm"><span>QR</span><strong data-mixed-qr>{{ \App\Support\UiFormatter::money($pendingBalance) }}</strong></p>
                <button class="btn-primary w-full" data-payment-submit>CONFIRMAR PAGO MIXTO</button>
            </form>
        </div>
        @endcan
    @endif
</section>
<script>
document.querySelectorAll('[data-payment-selector]').forEach((selector) => {
    const toCents = (value) => {
        const normalized = String(value || '').trim().replace(',', '.');
        const negative = normalized.startsWith('-');
        const parts = normalized.replace('-', '').split('.');
        const cents = (Number.parseInt(parts[0] || '0', 10) * 100)
            + Number.parseInt(((parts[1] || '') + '00').slice(0, 2), 10);

        return negative ? -cents : cents;
    };
    const money = (cents) => 'Bs ' + Math.trunc(cents / 100) + ',' + String(Math.abs(cents % 100)).padStart(2, '0');
    const balance = toCents(selector.dataset.balance);

    selector.querySelectorAll('[data-payment-method]').forEach((button) => {
        button.addEventListener('click', () => {
            selector.querySelector('[data-payment-form="mixed"]').hidden = false;
        });
    });

    const mixedForm = selector.querySelector('[data-payment-form="mixed"]');
    const mixedCash = mixedForm.querySelector('[data-mixed-cash]');
    const updateMixed = () => {
        const cash = toCents(mixedCash.value);
        mixedCash.setCustomValidity(cash < 0 || cash > balance ? 'El efectivo debe estar entre cero y el saldo.' : '');
        mixedForm.querySelector('[data-mixed-qr]').textContent = money(Math.max(0, balance - cash));
    };
    mixedCash.addEventListener('input', updateMixed);
    updateMixed();

    selector.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const submitter = event.submitter ?? form.querySelector('[data-payment-submit]');
            selector.querySelectorAll('button').forEach((button) => {
                button.disabled = true;
            });
            if (submitter) {
                submitter.textContent = 'Procesando...';
            }
        });
    });
});
</script>
@endif
