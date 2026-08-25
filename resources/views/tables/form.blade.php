@extends('layouts.app')
@section('title', $table ? 'Editar mesa' : 'Nueva mesa')
@section('heading', 'Mesas')
@section('content')
<div class="mx-auto max-w-2xl">
    <div class="page-heading"><div><a class="back-link" href="{{ route('tables.index') }}">← Volver</a><h1>{{ $table ? 'Editar mesa' : 'Nueva mesa' }}</h1><p>Configuración para la sucursal {{ request()->attributes->get('branch')->name }}.</p></div></div>
    <form class="card space-y-5 p-6" method="POST" action="{{ $table ? route('tables.update', $table->ulid) : route('tables.store') }}">
        @csrf
        @if($table) @method('PUT') @endif
        <div><label class="label" for="name">Nombre o número</label><input class="input" id="name" name="name" maxlength="50" value="{{ old('name', $table?->name) }}" placeholder="Mesa 1" required autofocus></div>
        <div class="grid gap-4 sm:grid-cols-2"><div><label class="label" for="capacity">Capacidad</label><input class="input" id="capacity" type="number" min="1" max="1000" name="capacity" value="{{ old('capacity', $table?->capacity) }}"></div><div><label class="label" for="sort_order">Orden</label><input class="input" id="sort_order" type="number" min="0" name="sort_order" value="{{ old('sort_order', $table?->sort_order ?? 0) }}" required></div></div>
        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $table?->is_active ?? true))><span>Mesa activa</span></label>
        <p class="text-sm text-stone-500">El estado Libre/Ocupada se deriva de su cuenta activa. Una mesa con una cuenta abierta no puede desactivarse.</p>
        <button class="btn-primary w-full">{{ $table ? 'Guardar cambios' : 'Crear mesa' }}</button>
    </form>
</div>
@endsection
