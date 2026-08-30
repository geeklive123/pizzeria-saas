@if(! $isPerBatch && $order->type === \App\Enums\OrderType::DineIn && $order->status === \App\Enums\OrderStatus::ReadyForPayment)
<section class="border-t border-orange-200 bg-orange-50 p-5"
         data-at-end-payment
         data-payment-selector
         data-balance="{{ $orderBalance }}">

    <h3 class="text-sm font-bold uppercase tracking-wide text-orange-900">
        COBRAR CUENTA
    </h3>

    <p class="mt-1 text-xs text-stone-600">
        Selecciona cómo pagará el total de la cuenta.
    </p>

    <div class="mt-4 space-y-2 text-sm">
        <p class="flex justify-between gap-4">
            <span>Total cuenta</span>
            <strong>{{ \App\Support\UiFormatter::money($order->total) }}</strong>
        </p>

        <p class="flex justify-between gap-4">
            <span>Total pagado</span>
            <strong>{{ \App\Support\UiFormatter::money($orderPaid) }}</strong>
        </p>

        <p class="flex justify-between gap-4 border-t border-orange-200 pt-3 text-lg font-bold">
            <span>Saldo</span>
            <strong>{{ \App\Support\UiFormatter::money($orderBalance) }}</strong>
        </p>
    </div>

    @if(!$cashSession)
        <div class="mt-4 rounded-xl bg-amber-100 p-3 text-sm text-amber-900">
            Debes abrir tu turno de caja antes de cobrar.
            <a class="link" href="{{ route('cash.open.form') }}">Abrir caja</a>
        </div>
    @else
        @can('create', $paymentClass)

        <div class="mt-5">
            <h4 class="text-sm font-bold">FORMA DE PAGO</h4>

            <div class="mt-2 grid grid-cols-3 gap-2">

                {{-- EFECTIVO: cobra todo el saldo directamente --}}
                <form method="POST"
                      action="{{ route('orders.payments.store', $order->ulid) }}"
                      data-quick-payment>
                    @csrf

                    <input type="hidden" name="method" value="cash">
                    <input type="hidden" name="amount" value="{{ $orderBalance }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyCash }}">

                    <button class="btn-secondary w-full px-2"
                            data-payment-submit>
                        EFECTIVO
                    </button>
                </form>

                {{-- QR: cobra todo el saldo directamente --}}
                <form method="POST"
                      action="{{ route('orders.payments.store', $order->ulid) }}"
                      data-quick-payment>
                    @csrf

                    <input type="hidden" name="method" value="qr">
                    <input type="hidden" name="amount" value="{{ $orderBalance }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyQr }}">

                    <button class="btn-secondary w-full px-2"
                            data-payment-submit>
                        QR
                    </button>
                </form>

                {{-- MIXTO: único método que abre formulario --}}
                <button class="btn-secondary px-2"
                        type="button"
                        data-payment-method="mixed">
                    MIXTO
                </button>
            </div>

            {{-- PAGO MIXTO --}}
            <form class="mt-3 space-y-3 rounded-xl border bg-white p-3"
                  method="POST"
                  action="{{ route('orders.payments.store', $order->ulid) }}"
                  data-payment-form="mixed"
                  hidden>
                @csrf

                <input type="hidden" name="method" value="mixed">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyMixedCash }}">

                <p class="flex justify-between text-sm">
                    <span>Saldo total</span>
                    <strong>{{ \App\Support\UiFormatter::money($orderBalance) }}</strong>
                </p>

                <label class="block">
                    <span class="label">Efectivo</span>
                    <input class="input"
                           name="cash_amount"
                           value="0.00"
                           inputmode="decimal"
                           required
                           data-mixed-cash>
                </label>

                <p class="flex justify-between text-sm">
                    <span>QR</span>
                    <strong data-mixed-qr>
                        {{ \App\Support\UiFormatter::money($orderBalance) }}
                    </strong>
                </p>

                <button class="btn-primary w-full"
                        data-payment-submit>
                    CONFIRMAR PAGO MIXTO
                </button>
            </form>
        </div>

        @endcan
    @endif
</section>

<script>
document.querySelectorAll('[data-at-end-payment][data-payment-selector]').forEach((selector) => {
    const toCents = (value) => {
        const normalized = String(value || '').trim().replace(',', '.');
        const negative = normalized.startsWith('-');
        const parts = normalized.replace('-', '').split('.');

        const cents = (Number.parseInt(parts[0] || '0', 10) * 100)
            + Number.parseInt(((parts[1] || '') + '00').slice(0, 2), 10);

        return negative ? -cents : cents;
    };

    const money = (cents) =>
        'Bs ' +
        Math.trunc(cents / 100) +
        ',' +
        String(Math.abs(cents % 100)).padStart(2, '0');

    const balance = toCents(selector.dataset.balance);

    const mixedButton = selector.querySelector('[data-payment-method="mixed"]');
    const mixedForm = selector.querySelector('[data-payment-form="mixed"]');

    if (mixedButton && mixedForm) {
        mixedButton.addEventListener('click', () => {
            mixedForm.hidden = false;

            const input = mixedForm.querySelector('[data-mixed-cash]');
            if (input) {
                input.focus();
                input.select();
            }
        });

        const mixedCash = mixedForm.querySelector('[data-mixed-cash]');
        const mixedQr = mixedForm.querySelector('[data-mixed-qr]');

        const updateMixed = () => {
            const cash = toCents(mixedCash.value);

            mixedCash.setCustomValidity(
                cash < 0 || cash > balance
                    ? 'El efectivo debe estar entre cero y el saldo.'
                    : ''
            );

            mixedQr.textContent = money(Math.max(0, balance - cash));
        };

        mixedCash.addEventListener('input', updateMixed);
        updateMixed();
    }

    selector.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const submitter =
                event.submitter ??
                form.querySelector('[data-payment-submit]');

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
