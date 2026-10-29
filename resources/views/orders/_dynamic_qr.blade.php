<details class="mt-3 rounded-xl border border-stone-200 bg-white p-4" open>
<summary class="cursor-pointer font-bold">Efectivo</summary>
<form class="mt-3 space-y-3" method="POST" action="{{ route('orders.payments.store', $order->ulid) }}" data-cash-payment>
    @csrf
    <input type="hidden" name="method" value="cash">
    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
    @if($qrDispatch)<input type="hidden" name="kitchen_dispatch" value="{{ $qrDispatch->ulid }}">@endif
    <label class="block"><span class="label">Monto aplicado en efectivo</span><input class="input" name="amount" value="{{ $qrBalance }}" inputmode="decimal" required></label>
    <label class="block"><span class="label">Efectivo recibido</span><input class="input" name="received_amount" value="{{ $qrBalance }}" inputmode="decimal" required></label>
    <p class="text-sm">Cambio: <strong data-cash-change>Bs 0,00</strong></p>
    <button class="btn-secondary w-full" type="submit">Registrar efectivo</button>
</form>
</details>
<section class="mt-3 rounded-xl border border-orange-300 bg-orange-50 p-4" data-dynamic-qr
    data-enabled="{{ config('baneco.enabled') ? '1' : '0' }}"
    data-create-url="{{ route('orders.dynamic-qr.store', $order->ulid) }}"
    data-current-url="{{ route('orders.dynamic-qr.show', ['order' => $order->ulid, 'dispatch' => $qrDispatch?->ulid]) }}"
    data-dispatch="{{ $qrDispatch?->ulid }}"
    data-key="{{ (string) \Illuminate\Support\Str::ulid() }}">
    <h4 class="font-bold">Banco Económico · QR</h4>
    <p class="mt-1 text-sm">Cobra el saldo pendiente con QR.</p>
    @if(! config('baneco.enabled'))<p class="mt-2 font-semibold text-red-700">Banco Económico no habilitado</p>@endif
    <button type="button" class="btn-primary mt-3 w-full" data-qr-open>Cobrar con QR</button>
    <button type="button" class="btn-secondary mt-2 w-full" data-qr-open-mixed>Pago mixto: efectivo + QR</button>
    <dialog data-qr-modal aria-labelledby="qr-title-{{ $qrDispatch?->ulid ?? $order->ulid }}" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl bg-white p-0 shadow-xl backdrop:bg-black/60">
        <div class="p-5 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div><h4 id="qr-title-{{ $qrDispatch?->ulid ?? $order->ulid }}" class="text-xl font-bold">Banco Económico</h4><p class="text-sm text-stone-600">Pedido {{ $order->operational_number ?? $order->ulid }}@if($qrDispatch) · Tanda {{ $qrDispatch->sequence_number }}@endif</p></div>
                <button type="button" class="btn-secondary" data-qr-close aria-label="Cerrar ventana de QR">Cerrar</button>
            </div>
            <div class="mt-4 rounded-xl bg-stone-50 p-3 text-sm space-y-1">
                <p data-qr-cash-paid>Efectivo registrado: Bs 0,00</p>
                <p data-qr-paid>Total pagado confirmado: Bs 0,00</p>
                <p data-qr-balance>Saldo restante: calculando…</p>
                <p data-qr-qr-pending>QR pendiente: Bs 0,00</p>
            </div>
            <div data-qr-mixed-form hidden class="mt-4 space-y-3">
                <p class="text-sm">El efectivo se registra antes de solicitar el QR y se conserva si la generación falla.</p>
                <label class="block"><span class="label">Efectivo aplicado</span><input class="input w-full" data-qr-cash inputmode="decimal" autocomplete="off"></label>
                <label class="block"><span class="label">Efectivo recibido</span><input class="input w-full" data-qr-received inputmode="decimal" autocomplete="off"></label>
                <p data-qr-estimate>QR restante estimado: —</p><p data-qr-change>Cambio estimado: —</p>
                <label class="block"><input type="checkbox" data-qr-confirm-cash> Confirmo que recibí este efectivo y deseo registrarlo.</label>
                <button type="button" class="btn-primary w-full" data-qr-mixed-submit>Registrar efectivo y generar QR</button>
            </div>
            <p class="mt-5 text-center text-2xl font-bold" data-qr-amount></p>
            <img data-qr-image hidden alt="QR de Banco Económico para pagar el importe indicado" class="mx-auto mt-4 w-80 max-w-full bg-white p-2">
            <p class="mt-3 text-center text-sm" data-qr-reference></p>
            <p class="text-center text-sm text-stone-600" data-qr-identifier></p>
            <p class="text-center text-sm text-stone-600" data-qr-expiry></p>
            <p class="mt-4 text-center font-semibold" data-qr-status role="status" aria-live="polite"></p>
            <p class="mt-2 text-center text-sm text-red-700" data-qr-error role="alert"></p>
            <p class="mt-2 text-center text-xs text-stone-600">El cobro se registra solo cuando el banco confirma el pago.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <button type="button" class="btn-secondary" data-qr-check hidden>Consultar estado</button>
                <button type="button" class="btn-secondary" data-qr-cancel hidden>Solicitar cancelación</button>
                <button type="button" class="btn-primary" data-qr-generate hidden>Generar nuevo QR</button>
            </div>
        </div>
    </dialog>
</section>
