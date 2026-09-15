@extends('layouts.app')

@section('title', 'Nueva receta')
@section('heading', 'Recetas')

@section('content')
<div class="page-heading">
    <div>
        <a class="back-link" href="{{ route('recipes.index') }}">← Volver a recetas</a>
        <h1>Nueva receta</h1>
        <p>Selecciona una variante preparada que todavía no tenga una receta configurada.</p>
    </div>
</div>

<section class="card p-5 sm:p-7">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($variants as $variant)
            <article class="rounded-2xl border border-stone-200 p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $variant->product->category?->name ?: 'Producto preparado' }}</p>
                <h2 class="mt-1 text-lg font-semibold">{{ $variant->product->name }}</h2>
                <p class="text-sm text-stone-600">{{ \App\Support\UiFormatter::variantName($variant->name, $variant->size_key) }} · {{ \App\Support\UiFormatter::money($variant->price) }}</p>
                <a class="btn-primary mt-4 w-full" href="{{ route('recipes.edit', $variant->ulid) }}">Configurar receta</a>
            </article>
        @empty
            <div class="empty-state col-span-full">
                <p class="font-semibold">No hay tamaños pendientes de receta.</p>
                <p class="mt-2">Para crear una nueva receta, primero crea un nuevo producto o agrega un tamaño al sabor existente.</p>
                <a class="btn-primary mt-4" href="{{ route('products.index') }}">Ir a Productos</a>
            </div>
        @endforelse
    </div>
</section>
@endsection
