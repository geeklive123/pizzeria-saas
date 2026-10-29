@extends('layouts.app')

@section('title', 'Cobrar '.$order->formattedNumber())
@section('heading', 'Cobrar')

@section('content')
<div class="page-heading">
    <div>
        <a class="back-link" href="{{ route('orders.show', $order->ulid) }}">← Volver al pedido</a>
        <h1>{{ $order->restaurantTable?->name ?? 'Para llevar' }} · {{ $order->formattedNumber() }}</h1>
        <p>Registra pagos sin perder el seguimiento de cocina.</p>
    </div>
    @can('create', $paymentClass)
        <form method="POST" action="{{ route('orders.ticket.print', $order->ulid) }}">
            @csrf
            <button class="btn-secondary">{{ $lastTicketAttempt?->status === \App\Enums\PrintAttemptStatus::Failed ? 'Reintentar ticket' : ($hasPrintedTicket ? 'Reimprimir ticket' : 'Imprimir ticket') }}</button>
            @if ($lastTicketAttempt)
                <span class="ml-2 text-xs text-stone-500">Impresión: {{ $lastTicketAttempt->status->label() }}</span>
            @endif
        </form>
    @endcan
</div>

<div class="grid gap-6 xl:grid-cols-[.8fr_1.2fr]">
    <section class="card self-start p-6">
        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1 2xl:grid-cols-3">
            <div class="rounded-2xl bg-stone-100 p-4"><p class="text-sm text-stone-500">Total</p><strong class="mt-1 block text-xl">{{ $formatter::money($order->total) }}</strong></div>
            <div class="rounded-2xl bg-emerald-50 p-4 text-emerald-800"><p class="text-sm">Pagado</p><strong class="mt-1 block text-xl">{{ $formatter::money($paid) }}</strong></div>
            <div class="rounded-2xl bg-orange-50 p-4 text-orange-800"><p class="text-sm">Saldo restante</p><strong class="mt-1 block text-2xl">{{ $formatter::money($balance) }}</strong></div>
        </div>
        <div class="mt-4 space-y-2 text-sm text-stone-600">
            <p class="flex justify-between"><span>Subtotal</span><strong>{{ $formatter::money($order->subtotal) }}</strong></p>
            <p class="flex justify-between"><span>Descuento</span><strong>{{ $formatter::money($order->discount_total) }}</strong></p>
        </div>

        @if ($balance === '0.00' && $order->status !== \App\Enums\OrderStatus::Paid)
            <div class="mt-6 rounded-xl bg-blue-50 p-4 text-blue-900">
                <p class="font-semibold">Pago completado; pedido aún operativo.</p>
                <p class="mt-1 text-sm">Aún hay productos pendientes en cocina. El pedido se finalizará y la mesa se liberará cuando todos estén servidos.</p>
            </div>
        @elseif (! $session)
            <div class="mt-6 rounded-xl bg-amber-50 p-4 text-amber-900">
                <p class="font-semibold">Debes abrir tu propio turno de caja antes de cobrar.</p>
                <a class="link mt-2 inline-block" href="{{ route('cash.open.form') }}">Abrir caja</a>
            </div>
        @elseif ($order->status !== \App\Enums\OrderStatus::Paid && $balance !== '0.00')
            @can('create', $paymentClass)
                    @include('orders._dynamic_qr', ['qrDispatch' => $dispatch, 'qrBalance' => $balance])
            @endcan
        @endif
    </section>

    <section class="card">
        <div class="card-header"><div><h2 class="card-title">Pagos realizados</h2><p class="card-subtitle">Historial individual para pagos simples, mixtos y parciales.</p></div></div>
        <div class="divide-y">
            @forelse ($order->payments->sortByDesc('paid_at') as $payment)
                <div class="p-5">
                    <div class="flex justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $payment->status === \App\Enums\PaymentStatus::Completed ? '✓' : '↩' }} {{ $formatter::paymentMethod($payment->method) }} · {{ $formatter::paymentStatus($payment->status) }}</p>
                            <p class="text-xs text-stone-500">{{ $formatter::date($payment->paid_at, true) }} · {{ $payment->receivedBy->name }}</p>
                            @if ($payment->method === \App\Enums\PaymentMethod::Cash && $payment->received_amount)
                                <p class="text-xs text-stone-500">Recibido {{ $formatter::money($payment->received_amount) }} · Vuelto {{ $formatter::money($payment->change_amount) }}</p>
                            @endif
                        </div>
                        <strong>{{ $formatter::money($payment->amount) }}</strong>
                    </div>
                    @if ($payment->status === \App\Enums\PaymentStatus::Completed && $order->status !== \App\Enums\OrderStatus::Paid)
                        @can('reverse', $payment)
                            <form class="mt-3 flex gap-2" method="POST" action="{{ route('payments.reverse', $payment->ulid) }}">
                                @csrf
                                <input class="input" name="reason" placeholder="Motivo de reversión" required>
                                <button class="btn-danger">Revertir</button>
                            </form>
                        @endcan
                    @endif
                </div>
            @empty
                <div class="empty-state">Aún no hay pagos.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection
