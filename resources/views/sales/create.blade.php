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
                @if($table->openOrder)
                    <form method="POST" action="{{ route('tables.open', $table->ulid) }}">@csrf<input type="hidden" name="charge_mode" value="{{ $table->openOrder->charge_mode->value }}"><button class="w-full rounded-2xl border border-amber-300 bg-amber-50 p-4 text-left transition hover:border-orange-400 hover:bg-orange-50"><span class="flex items-center justify-between gap-2"><strong>{{ $table->name }}</strong><span class="badge bg-amber-100 text-amber-800">Ocupada</span></span><span class="mt-2 block text-xs text-stone-600">{{ $table->capacity ? $table->capacity.' personas' : 'Capacidad no configurada' }}</span><span class="mt-2 block text-sm font-semibold text-orange-700">Continuar {{ $table->openOrder->formattedNumber() }}</span></button></form>
                @else
                    <details class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><summary class="cursor-pointer list-none"><span class="flex items-center justify-between gap-2"><strong>{{ $table->name }}</strong><span class="badge badge-success">Libre</span></span><span class="mt-2 block text-xs text-stone-600">{{ $table->capacity ? $table->capacity.' personas' : 'Capacidad no configurada' }}</span><span class="mt-2 block text-sm font-semibold text-emerald-700">Abrir cuenta</span></summary><form method="POST" action="{{ route('tables.open', $table->ulid) }}" class="mt-4 space-y-3 border-t border-emerald-200 pt-4">@csrf<fieldset><legend class="label">&iquest;C&oacute;mo cobrar&aacute; esta mesa?</legend><div class="mt-2 grid gap-2">@foreach(\App\Enums\TableChargeMode::cases() as $mode)<label class="flex cursor-pointer items-center gap-2 rounded-xl border border-stone-200 bg-white p-3"><input type="radio" name="charge_mode" value="{{ $mode->value }}" required><span>{{ $mode->label() }}</span></label>@endforeach</div></fieldset><label class="block"><span class="label">Cliente (opcional)</span><input class="input" name="customer_name" maxlength="255"></label><button class="btn-primary w-full">Abrir mesa</button></form></details>
                @endif
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
