@extends('layouts.app')
@section('title','Mesas')
@section('heading','Mesas')
@section('header-subtitle','Administra la configuración de las mesas de la sucursal')
@section('content')
<div class="page-heading">
    <div><h1>Administración de mesas</h1><p>Crea, edita y consulta mesas. La operación diaria se realiza desde Venta.</p></div>
    <div class="flex gap-2"><a class="btn-secondary" href="{{ route('sales.create') }}">Ir a Venta</a>@can('create',\App\Models\RestaurantTable::class)<a class="btn-primary" href="{{ route('tables.create') }}">+ Nueva mesa</a>@endcan</div>
</div>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
@forelse($tables as $table)
    @php($hasReady=$table->openOrder?->items->contains('status',\App\Enums\OrderItemStatus::Ready) ?? false)
    <article class="card p-5 {{ $table->is_active ? '' : 'opacity-60' }}">
        <div class="flex items-start justify-between gap-3">
            <div><p class="text-xl font-semibold">{{ $table->name }}</p><p class="mt-1 text-xs text-stone-500">{{ $table->capacity ? $table->capacity.' personas' : 'Capacidad no configurada' }}</p></div>
            <span class="badge {{ ! $table->is_active ? '' : ($hasReady ? 'badge-danger' : ($table->openOrder ? 'bg-amber-100 text-amber-800' : 'badge-success')) }}">{{ ! $table->is_active ? 'Inactiva' : ($hasReady ? 'Atención' : ($table->openOrder ? 'Ocupada' : 'Libre')) }}</span>
        </div>
        <div class="mt-5 rounded-xl bg-stone-50 p-4 text-sm">
            @if($table->openOrder)
                <p class="font-semibold">{{ $table->openOrder->formattedNumber() }}</p><p class="mt-1 text-xs text-stone-500">Cuenta activa · {{ \App\Support\UiFormatter::money($table->openOrder->total) }}</p>
                @if($hasReady)<p class="mt-2 text-xs font-semibold text-red-700">Tiene productos listos</p>@endif
            @else
                <p class="text-stone-500">{{ $table->is_active ? 'Disponible para una nueva cuenta.' : 'No disponible para operación.' }}</p>
            @endif
        </div>
        @can('update',$table)<a class="btn-secondary mt-4 w-full" href="{{ route('tables.edit',$table->ulid) }}">Configurar mesa</a>@endcan
    </article>
@empty
    <div class="empty-state card sm:col-span-2"><p>No hay mesas configuradas para esta sucursal.</p>@can('create',\App\Models\RestaurantTable::class)<a class="btn-primary mt-3 inline-flex" href="{{ route('tables.create') }}">Crear primera mesa</a>@else<p class="mt-2 text-sm">Solicita a un administrador que configure las mesas.</p>@endcan</div>
@endforelse
</div>
@endsection
