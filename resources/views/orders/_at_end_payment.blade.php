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

    @if ($canApplyOrderDiscount)
        @if (\Brick\Math\BigDecimal::of($order->pizza_base_subtotal)->isGreaterThan('80.00') && ! $orderHasPayments)
            <form class='mt-4 rounded-xl border border-orange-200 bg-white p-3' method='POST' action='{{ route('orders.request-payment', $order->ulid) }}'>
                @csrf
                <label class='block'>
                    <span class='label'>Descuento en pizzas</span>
                    <span class='mt-1 flex items-center gap-2'>
                        <input class='input w-32' name='discount_percentage' value='{{ old('discount_percentage', $order->discount_percentage ?? '0') }}' inputmode='decimal' required>
                        <span>%</span>
                    </span>
                </label>
                <p class='mt-2 text-xs text-stone-500'>Usa 0 para quitar el descuento antes de cobrar.</p>
                <button class='btn-secondary mt-3 w-full' type='submit'>ACTUALIZAR DESCUENTO</button>
            </form>
        @endif
    @endif

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
            @include('orders._dynamic_qr', ['qrDispatch' => null, 'qrBalance' => $orderBalance])
        @endcan
    @endif
</section>

@endif
