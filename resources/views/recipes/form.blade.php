@extends('layouts.app')

@php($recipe = $variant->recipe)
@section('title', $recipe ? 'Editar receta' : 'Crear receta')
@section('heading', 'Recetas')

@section('content')
<div class="page-heading">
    <div>
        <a class="back-link" href="{{ route('recipes.index') }}">← Volver a recetas</a>
        <h1>{{ $recipe ? 'Editar receta' : 'Crear receta' }}</h1>
        <p>{{ $variant->product->name }} · {{ $variant->name }} · {{ \App\Support\UiFormatter::money($variant->price) }}</p>
    </div>
</div>

<form method="POST" action="{{ route('recipes.update', $variant->ulid) }}" class="space-y-6">
    @csrf
    @method('PUT')
    <section class="card p-5 sm:p-7">
        <div class="form-grid">
            <div class="sm:col-span-2">
                <label class="label" for="name">Nombre de la receta</label>
                <input class="input" id="name" name="name" value="{{ old('name', $recipe?->name ?? 'Receta '.$variant->product->name.' '.$variant->name) }}" required>
            </div>
            <label class="sm:col-span-2 flex items-center gap-3 rounded-xl bg-stone-50 p-4">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $recipe?->is_active ?? true))>
                <span class="text-sm font-medium">Receta activa</span>
            </label>
        </div>
    </section>

    <section class="card p-5 sm:p-7">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="card-title">Ingredientes</h2>
                <p class="card-subtitle">La cantidad siempre usa la unidad base mostrada junto a cada ingrediente.</p>
            </div>
            <button type="button" class="btn-secondary" data-add-row="recipe">+ Agregar ingrediente</button>
        </div>
        @php($recipeRows = old('items', $recipe?->items->map(fn ($item) => ['ingredient_id' => $item->ingredient_id, 'component_type' => $item->component_type->value, 'quantity' => $item->quantity])->all() ?? [['ingredient_id' => '', 'component_type' => 'topping', 'quantity' => '']]))
        <div class="mt-5 space-y-3" data-rows="recipe">
            @foreach ($recipeRows as $index => $row)
                @include('recipes.item-row', ['index' => $index, 'row' => $row])
            @endforeach
        </div>
    </section>

    <div class="flex justify-end gap-3">
        <a class="btn-secondary" href="{{ route('recipes.index') }}">Cancelar</a>
        <button class="btn-primary">Guardar receta</button>
    </div>
</form>

<template id="recipe-template">
    @include('recipes.item-row', ['index' => '__INDEX__', 'row' => ['ingredient_id' => '', 'component_type' => 'topping', 'quantity' => '']])
</template>
@endsection
