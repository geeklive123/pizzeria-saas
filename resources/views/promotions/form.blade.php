@extends('layouts.app')
@section('title', $promotion->exists ? 'Editar promoción' : 'Nueva promoción')
@section('heading', 'Promociones')
@section('content')
@php($product = $promotion->productVariant?->product)
@php($componentRows = old('components', $promotion->exists ? $promotion->components->map(fn ($component) => ['inventory_item_ulid' => $component->inventoryItem->ulid, 'quantity' => $component->quantity])->values()->all() : [['inventory_item_ulid' => '', 'quantity' => '']]))
<div class="mx-auto max-w-4xl">
    <div class="page-heading"><div><a class="back-link" href="{{ route('promotions.index') }}">← Volver a promociones</a><h1>{{ $promotion->exists ? 'Editar promoción' : 'Nueva promoción' }}</h1><p>El precio y los componentes quedan bajo autoridad del servidor. Las cantidades usan la unidad del artículo de inventario.</p></div></div>
    <form method="POST" action="{{ $promotion->exists ? route('promotions.update', $promotion->ulid) : route('promotions.store') }}" class="space-y-6">
        @csrf @if($promotion->exists)@method('PUT')@endif
        <section class="card p-5 sm:p-8">
            <div class="form-grid">
                <div class="sm:col-span-2"><label class="label" for="name">Nombre</label><input class="input" id="name" name="name" value="{{ old('name', $product?->name) }}" maxlength="255" required></div>
                <div><label class="label" for="price">Precio final (Bs)</label><input class="input" id="price" name="price" type="number" min="0" step="0.01" value="{{ old('price', $promotion->productVariant?->price ?? '0.00') }}" required></div>
                <div><label class="label" for="starts_at">Inicio opcional</label><input class="input" id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', $promotion->starts_at?->format('Y-m-d\TH:i')) }}"></div>
                <div><label class="label" for="ends_at">Fin opcional</label><input class="input" id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', $promotion->ends_at?->format('Y-m-d\TH:i')) }}"></div>
                <div class="sm:col-span-2"><label class="label" for="description">Descripción opcional</label><textarea class="input min-h-24" id="description" name="description">{{ old('description', $product?->description) }}</textarea></div>
                <label class="sm:col-span-2 flex items-center gap-3 rounded-xl bg-stone-50 p-4"><input class="size-5" type="checkbox" name="is_active" value="1" @checked(old('is_active', $promotion->is_active ?? true))><span><strong class="block text-sm">Promoción activa</strong><span class="text-xs text-stone-500">Solo aparecerá en el POS durante su ventana de vigencia.</span></span></label>
            </div>
        </section>
        <section class="card p-5 sm:p-8">
            <div class="flex items-start justify-between gap-4"><div><h2 class="card-title">Componentes de inventario</h2><p class="card-subtitle">Ejemplo vino: 0.500 botella por promoción de dos copas.</p></div><button class="btn-secondary" type="button" data-add-row="promotion-components">+ Componente</button></div>
            <div class="mt-5 space-y-3" data-rows="promotion-components">
                @foreach($componentRows as $index => $component) @include('promotions.component-row', compact('index', 'component', 'inventoryItems')) @endforeach
            </div>
        </section>
        <div class="flex justify-end gap-3"><a class="btn-secondary" href="{{ route('promotions.index') }}">Cancelar</a><button class="btn-primary">Guardar promoción</button></div>
    </form>
</div>
<template id="promotion-components-template">@include('promotions.component-row', ['index' => '__INDEX__', 'component' => ['inventory_item_ulid' => '', 'quantity' => ''], 'inventoryItems' => $inventoryItems])</template>
@endsection
