@if($isPerBatch && $pendingDispatch)
<section class="border-t border-orange-200 bg-orange-50 p-5" data-per-batch-payment>
    <h3 class="text-sm font-bold uppercase tracking-wide text-orange-900">TANDA #{{ $pendingDispatch->sequence_number }} PENDIENTE DE PAGO</h3>
    <div class="mt-3 space-y-1 text-sm">
        @foreach($pendingDispatch->items as $dispatchItem)
            <p class="flex justify-between gap-3"><span>{{ $dispatchItem->orderItem->displayName() }}</span><strong>{{ \App\Support\UiFormatter::money($dispatchItem->gross_total) }}</strong></p>
        @endforeach
    </div>

    @if ($canApplyOrderDiscount && \Brick\Math\BigDecimal::of($pendingDispatch->pizza_base_subtotal)->isGreaterThan(\App\Services\OrderFinancialService::DISCOUNT_THRESHOLD) && ! $pendingHasPayments)
        <form class='mt-4 rounded-xl border border-orange-200 bg-white p-3' method='POST' action='{{ route('orders.dispatches.discount', [$order->ulid, $pendingDispatch->ulid]) }}'>
            @csrf
            <label class='block'>
                <span class='label'>Descuento en pizzas de esta tanda</span>
                <span class='mt-1 flex items-center gap-2'>
                    <input class='input w-32' name='discount_percentage' value='{{ old('discount_percentage', $pendingDispatch->discount_percentage ?? '0') }}' inputmode='decimal' required>
                    <span>%</span>
                </span>
            </label>
            <p class='mt-2 text-xs text-stone-500'>Usa 0 para quitar el descuento antes del primer pago.</p>
            <button class='btn-secondary mt-3 w-full' type='submit'>ACTUALIZAR DESCUENTO DE TANDA</button>
        </form>
    @endif
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
            @include('orders._dynamic_qr', ['qrDispatch' => $pendingDispatch, 'qrBalance' => $pendingBalance])
        @endcan
    @endif
</section>
@endif
