@extends('layouts.app')
@section('title', 'Transferir pagos')
@section('heading', 'Pedidos')
@section('content')
<div class='mx-auto max-w-3xl space-y-6'>
<div class='page-heading'><div><a class='back-link' href='{{ route('orders.show',$order->ulid) }}'>← Volver al pedido</a><h1>Transferir a otra cajera</h1><p>Corrección administrativa de {{ $order->formattedNumber() }}.</p></div></div>
<section class='card p-6'><p class='font-semibold'>Pedido {{ $order->formattedNumber() }} · {{ $formatter::money($order->total) }}</p><h2 class='mt-5 text-lg font-semibold'>Pagos actuales</h2><div class='mt-3 divide-y divide-stone-100 rounded-2xl border border-stone-200'>@foreach($order->payments as $payment)<div class='p-4'><p class='font-medium'>{{ $formatter::paymentMethod($payment->method) }} · {{ $formatter::money($payment->amount) }}</p><p class='text-sm text-stone-500'>{{ $payment->receivedBy->name }} · {{ $payment->cashSession->cashRegister->name }} · sesión {{ $payment->cashSession->ulid }} · {{ $formatter::date($payment->paid_at,true) }}</p></div>@endforeach</div></section>
<form class='card space-y-5 p-6' method='POST' action='{{ route('orders.cash-session-transfer.store',$order->ulid) }}'>@csrf
<div><label class='label' for='destination_session'>Transferir a</label><select class='input' id='destination_session' name='destination_session' required><option value=''>Selecciona una sesión</option>
@foreach($sessions as $session)<option value='{{ $session->ulid }}' @selected(old('destination_session') === $session->ulid)>{{ $session->openedBy->name }} · {{ $session->cashRegister->name }} · {{ $formatter::date($session->opened_at,true) }} · {{ $session->status === \App\Enums\CashSessionStatus::Open ? 'Abierta' : 'Cerrada' }}</option>@endforeach
</select>@error('destination_session')<p class='mt-2 text-sm text-red-600'>{{ $message }}</p>@enderror</div>
<div><label class='label' for='reason'>Motivo de la transferencia</label><textarea class='input min-h-28' id='reason' name='reason' maxlength='500' required placeholder='Venta registrada accidentalmente con sesión de Valeria.'>{{ old('reason') }}</textarea>@error('reason')<p class='mt-2 text-sm text-red-600'>{{ $message }}</p>@enderror</div>
@error('transfer')<div class='rounded-2xl bg-red-50 p-4 text-sm text-red-700'>{{ $message }}</div>@enderror
<div class='rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900'>Esta acción modificará la asignación histórica de pagos y caja. No modifica productos ni inventario.</div>
<button class='btn-primary w-full'>Confirmar transferencia</button>
</form>
</div>
@endsection
