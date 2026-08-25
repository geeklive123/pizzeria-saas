@extends('layouts.app')
@section('title','Nuevo pedido para llevar')
@section('heading','Pedidos')
@section('content')
<div class="mx-auto max-w-2xl"><div class="page-heading"><div><a class="back-link" href="{{ route('sales.create') }}">← Volver a Venta</a><h1>Pedido para llevar</h1><p>Abre una cuenta sin mesa, agrega productos en el POS y continúa al checkout existente.</p></div></div><form class="card space-y-5 p-6" method="POST" action="{{ route('orders.takeaway.store') }}">@csrf<div><label class="label">Cliente</label><input class="input" name="customer_name" value="{{ old('customer_name') }}" autofocus></div><div><label class="label">Teléfono</label><input class="input" name="customer_phone" value="{{ old('customer_phone') }}"></div><div><label class="label">Notas</label><textarea class="input" name="notes">{{ old('notes') }}</textarea></div><button class="btn-primary w-full">Abrir pedido y entrar al POS</button></form></div>
@endsection
