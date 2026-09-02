@extends('layouts.app')
@section('title', $preparation->exists ? 'Editar preparación' : 'Nueva preparación')
@section('heading', 'Preparaciones internas')
@section('content')
<div class="page-heading"><div><a class="back-link" href="{{ route('preparations.index') }}">← Volver a preparaciones</a><h1>{{ $preparation->exists ? 'Editar '.$preparation->name : 'Nueva preparación' }}</h1><p>Define un rendimiento y el consumo por lote en la unidad base de cada ingrediente.</p></div></div>
<form method="POST" action="{{ $preparation->exists ? route('preparations.update', $preparation->ulid) : route('preparations.store') }}" class="space-y-6">
    @csrf @if($preparation->exists) @method('PUT') @endif
    <section class="card p-5 sm:p-7">
        <h2 class="card-title">Datos de la preparación</h2>
        <div class="form-grid mt-5">
            <div><label class="label" for="name">Nombre</label><input class="input" id="name" name="name" value="{{ old('name', $preparation->name) }}" required></div>
            <div><label class="label" for="output_inventory_item_id">Ingrediente de salida</label><select class="input" id="output_inventory_item_id" name="output_inventory_item_id" required><option value="">Selecciona…</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected((string)old('output_inventory_item_id', $preparation->output_inventory_item_id) === (string)$item->id)>{{ $item->name }} · {{ $item->unit->symbol }}</option>@endforeach</select><p class="field-help">Por ejemplo, Masa. No uses el producto de venta AGUA 600 ML.</p></div>
            <div><label class="label" for="theoretical_yield">Rendimiento teórico por lote</label><input class="input" id="theoretical_yield" name="theoretical_yield" value="{{ old('theoretical_yield', $preparation->theoretical_yield) }}" inputmode="decimal" required></div>
            <label class="flex items-center gap-3 rounded-xl bg-stone-50 p-4"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $preparation->is_active ?? true))><span class="text-sm font-medium">Preparación activa</span></label>
        </div>
    </section>
    <section class="card p-5 sm:p-7">
        <div class="flex items-center justify-between gap-4"><div><h2 class="card-title">Fórmula por lote</h2><p class="card-subtitle">Cada cantidad se registra en la unidad base mostrada en el selector.</p></div><button type="button" class="btn-secondary" data-add-row="preparation">+ Agregar componente</button></div>
        @php($componentRows = old('components', $preparation->exists ? $preparation->components->map(fn($component) => ['inventory_item_id' => $component->inventory_item_id, 'quantity' => $component->quantity])->all() : [['inventory_item_id' => '', 'quantity' => '']]))
        <div class="mt-5 space-y-3" data-rows="preparation">@foreach($componentRows as $index => $row) @include('preparations.component-row', compact('index', 'row', 'items')) @endforeach</div>
    </section>
    <div class="flex justify-end gap-3"><a class="btn-secondary" href="{{ route('preparations.index') }}">Cancelar</a><button class="btn-primary">Guardar preparación</button></div>
</form>
<template id="preparation-template">@include('preparations.component-row', ['index' => '__INDEX__', 'row' => ['inventory_item_id' => '', 'quantity' => ''], 'items' => $items])</template>
@endsection
