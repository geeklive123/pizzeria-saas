@extends('layouts.app')
@section('title', 'Pedidos online')
@section('heading', 'Pedidos online')
@section('content')
<div class="mx-auto max-w-[1500px]">
    <h1 class="text-3xl font-bold">Pedidos online</h1>
    <form method="GET" class="my-5 flex flex-wrap gap-3">
        <label>Estado <select name="status" class="input"><option value="">Todos</option>@foreach(['pending_confirmation' => 'Pendientes', 'confirmed' => 'Confirmados', 'cancelled' => 'Cancelados'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Desde <input class="input" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>Hasta <input class="input" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <button class="btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="hidden overflow-x-auto rounded-2xl border border-stone-200 bg-white md:block">
        <table class="w-full text-left text-sm"><thead><tr><th class="p-3">Solicitud</th><th>Fecha</th><th>Cliente</th><th>Celular</th><th>Productos</th><th>Total</th><th>Modalidad</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
        @forelse($orders as $order)
            <tr class="border-t border-stone-100"><td class="p-3">{{ $order->publicCode() }}</td><td>{{ \App\Support\UiFormatter::date($order->created_at, true) }}</td><td>{{ $order->customer_name }}</td><td>{{ $order->customer_phone }}</td><td>{{ $order->items_sum_quantity }}</td><td>Bs {{ $order->total }}</td><td>Recojo en local</td><td>{{ ['pending_confirmation' => 'Pendiente', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado'][$order->status] ?? $order->status }}</td><td><a class="link" href="{{ route('online-orders.show', $order->id) }}">Ver detalle</a></td></tr>
        @empty
            <tr><td class="p-4" colspan="9">No hay solicitudes.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="space-y-3 md:hidden">
        @forelse($orders as $order)
            <article class="rounded-2xl border border-stone-200 bg-white p-4">
                <div class="flex items-center justify-between gap-3"><strong>{{ $order->publicCode() }}</strong><span>{{ ['pending_confirmation' => 'Pendiente', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado'][$order->status] ?? $order->status }}</span></div>
                <p class="mt-2 text-sm text-stone-600">{{ \App\Support\UiFormatter::date($order->created_at, true) }} · Recojo en local</p>
                <p class="mt-2 font-semibold">{{ $order->customer_name }}</p><p>{{ $order->customer_phone }}</p>
                <p class="mt-2">{{ $order->items_sum_quantity }} productos · <strong>Bs {{ $order->total }}</strong></p>
                <a class="link mt-3 inline-block" href="{{ route('online-orders.show', $order->id) }}">Ver detalle</a>
            </article>
        @empty
            <p class="rounded-2xl border border-stone-200 bg-white p-4">No hay solicitudes.</p>
        @endforelse
    </div>
    {{ $orders->links() }}
</div>
@endsection
