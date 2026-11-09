@extends('layouts.app')

@section('title', 'Pedido actualizado')
@section('heading', 'Pedido actualizado')

@section('content')
<section class="card p-6" role="alert">
    <h1 class="text-xl font-bold">El pedido cambió desde que se abrió la pantalla.</h1>
    <p class="mt-3">No se registró este cobro. Saldo actualizado: <strong>{{ \App\Support\UiFormatter::money($balance) }}</strong>.</p>
    <p class="mt-3">Revisa el pedido antes de iniciar explícitamente otro cobro.</p>
    <a class="btn-primary mt-4 inline-block" href="{{ route('orders.show', $order->ulid) }}">Revisar pedido</a>
</section>
@endsection
