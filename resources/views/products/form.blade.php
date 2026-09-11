@extends('layouts.app')
@section('title', $product->exists ? 'Editar producto' : 'Nuevo producto')
@section('heading', 'Productos')
@section('content')
@if($categories->isEmpty())
    <div class='mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900'>
        <strong>No hay categorías activas.</strong> Puedes guardar el producto sin categoría.
        @can('create', \App\Models\Category::class)<a class='ml-2 font-semibold underline' href='{{ route('categories.create') }}'>Crear categoría</a>@else Solicita a un administrador que las configure.@endcan
    </div>
@endif
<div class="page-heading"><div><a class="back-link" href="{{ route('products.index') }}">← Volver a productos</a><h1>{{ $product->exists ? 'Editar producto' : 'Nuevo producto' }}</h1><p>Información comercial y opciones que verá el equipo.</p></div></div>
<form method="POST" action="{{ $product->exists ? route('products.update', $product->ulid) : route('products.store') }}" class="space-y-6">@csrf @if($product->exists)@method('PUT')@endif
<section class="card p-5 sm:p-7"><h2 class="card-title">Información general</h2><div class="form-grid mt-5">
    <div class="sm:col-span-2"><label class="label" for="name">Nombre</label><input class="input" id="name" name="name" value="{{ old('name', $product->name) }}" required></div>
    <div><label class="label" for="category_id">Categoría</label><select class="input" id="category_id" name="category_id"><option value="">Sin categoría</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string)old('category_id', $product->category_id)===(string)$category->id)>{{ $category->name }}</option>@endforeach</select></div>
    <div><label class="label" for="type">Tipo</label><select class="input" id="type" name="type">@foreach($types as $type)<option value="{{ $type->value }}" @selected(old('type', $product->type instanceof \App\Enums\ProductType ? $product->type->value : $product->type)===$type->value)>{{ \App\Support\UiFormatter::productType($type) }}</option>@endforeach</select></div>
    <div class="sm:col-span-2"><label class="label" for="description">Descripción</label><textarea class="input min-h-24" id="description" name="description">{{ old('description', $product->description) }}</textarea></div>
    <label class="sm:col-span-2 flex items-center gap-3 rounded-xl bg-stone-50 p-4"><input class="size-5" type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active ?? true))><span><strong class="block text-sm">Producto activo</strong><span class="text-xs text-stone-500">Disponible para la operación y el catálogo.</span></span></label>
</div></section>
<section class="card p-5 sm:p-7"><div class="flex items-center justify-between gap-4"><div><h2 class="card-title">Variantes y precios</h2><p class="card-subtitle">Tamaños o presentaciones del producto.</p></div><button class="btn-secondary" type="button" data-add-row="variants">+ Agregar variante</button></div>
<div id="variants-rows" class="mt-5 space-y-3" data-rows="variants">@foreach(old('variants', $variantRows) as $index => $variant)@include('products.variant-row', ['index'=>$index,'variant'=>$variant])@endforeach</div>
</section>
<div class="flex justify-end gap-3"><a class="btn-secondary" href="{{ route('products.index') }}">Cancelar</a><button class="btn-primary" type="submit">Guardar producto</button></div></form>
<template id="variants-template">@include('products.variant-row', ['index'=>'__INDEX__','variant'=>['name'=>'','sku'=>'','price'=>'0.00','requires_preparation'=>true,'track_stock'=>false,'inventory_unit_id'=>null,'is_active'=>true,'sort_order'=>'__INDEX__']])</template>
@endsection
