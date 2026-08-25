@extends('layouts.app')
@section('title','Pedidos abiertos')
@section('heading','Pedidos')
@section('content')
<div class="page-heading"><div><h1>Pedidos abiertos</h1><p>Cuentas en atención o listas para cobrar.</p></div><div class="flex gap-2"><a class="btn-secondary" href="{{ route('tables.index') }}">Ver mesas</a>@can('create',\App\Models\Order::class)<a class="btn-primary" href="{{ route('orders.takeaway.create') }}">+ Para llevar</a>@endcan</div></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Cuenta</th><th>Atención</th><th>Apertura</th><th>Total</th><th>Usuario</th><th></th></tr></thead><tbody>@forelse($orders as $order)<tr><td class="font-semibold">{{ $order->formattedNumber() }}</td><td>{{ $order->restaurantTable?->name ?? 'Para llevar' }}@if($order->customer_name)<p class="text-xs text-stone-500">{{ $order->customer_name }}</p>@endif</td><td>{{ \App\Support\UiFormatter::date($order->opened_at,true) }}</td><td class="font-semibold">{{ \App\Support\UiFormatter::money($order->total) }}</td><td>{{ $order->createdBy?->name ?? 'Sistema' }}</td><td class="text-right"><a class="btn-secondary" href="{{ route('orders.show',$order->ulid) }}">Continuar</a></td></tr>@empty<tr><td colspan="6"><div class="empty-state">No hay pedidos abiertos.</div></td></tr>@endforelse</tbody></table></div></div>
@endsection
