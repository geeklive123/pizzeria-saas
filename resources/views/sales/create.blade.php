@extends('layouts.app')
@section('title', 'Nueva venta')
@section('heading', 'Venta')
@section('content')
<div class="page-heading"><div><h1>Nueva venta</h1><p>Elige cómo iniciar la operación. El pedido continuará en el POS y luego en el checkout existente.</p></div></div>

<div class="grid gap-6 xl:grid-cols-2">
    <section class="card overflow-hidden">
        <div class="card-header"><div><p class="text-xs font-semibold uppercase tracking-wider text-orange-700">En mesa</p><h2 class="card-title mt-1">Selecciona una mesa</h2><p class="card-subtitle">Una mesa ocupada abre su cuenta actual; una libre crea una sola cuenta.</p></div></div>
        <div class="grid gap-3 p-5 sm:grid-cols-2">
            @forelse($tables as $table)
                <form method="POST" action="{{ route('tables.open', $table->ulid) }}">@csrf<button class="w-full rounded-2xl border p-4 text-left transition hover:border-orange-400 hover:bg-orange-50 {{ $table->openOrder ? 'border-amber-300 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }}"><span class="flex items-center justify-between gap-2"><strong>{{ $table->name }}</strong><span class="badge {{ $table->openOrder ? 'bg-amber-100 text-amber-800' : 'badge-success' }}">{{ $table->openOrder ? 'Ocupada' : 'Libre' }}</span></span><span class="mt-2 block text-xs text-stone-600">{{ $table->capacity ? $table->capacity.' personas' : 'Capacidad no configurada' }}</span>@if($table->openOrder)<span class="mt-2 block text-sm font-semibold text-orange-700">Continuar {{ $table->openOrder->formattedNumber() }}</span>@else<span class="mt-2 block text-sm font-semibold text-emerald-700">Abrir cuenta</span>@endif</button></form>
            @empty
                <div class="empty-state sm:col-span-2"><p>No hay mesas activas en esta sucursal.</p>@can('create',\App\Models\RestaurantTable::class)<a class="btn-primary mt-3 inline-flex" href="{{ route('tables.create') }}">Crear primera mesa</a>@else<p class="mt-2 text-sm">Solicita a un administrador que configure las mesas.</p>@endcan</div>
            @endforelse
        </div>
    </section>

    <section class="card">
        <div class="card-header"><div><p class="text-xs font-semibold uppercase tracking-wider text-orange-700">Para llevar</p><h2 class="card-title mt-1">Abrir pedido sin mesa</h2><p class="card-subtitle">Cliente, teléfono y notas son opcionales.</p></div></div>
        <form class="space-y-4 p-5" method="POST" action="{{ route('orders.takeaway.store') }}">@csrf<div><label class="label" for="customer_name">Cliente</label><input class="input" id="customer_name" name="customer_name" value="{{ old('customer_name') }}"></div><div><label class="label" for="customer_phone">Teléfono</label><input class="input" id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}"></div><div><label class="label" for="notes">Notas</label><textarea class="input" id="notes" name="notes">{{ old('notes') }}</textarea></div><button class="btn-primary w-full">Abrir pedido para llevar</button></form>
    </section>
</div>
@endsection
