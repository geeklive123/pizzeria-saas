@extends('layouts.app')

@section('title', 'Recetas')
@section('heading', 'Recetas')

@section('content')
<div class="page-heading">
    <div>
        <h1>Recetas</h1>
        <p>Configura qué ingredientes consume cada producto preparado.</p>
    </div>
    @can('create', \App\Models\Recipe::class)
        <a class="btn-primary" href="{{ route('recipes.create') }}">+ Nueva receta</a>
    @endcan
</div>

<section>
    <div class="mb-4">
        <h2 class="text-xl font-semibold">Productos preparados</h2>
        <p class="text-sm text-stone-500">Variantes que pasan por cocina y consumen ingredientes según su receta.</p>
    </div>
    <div class="space-y-5">
        @forelse ($preparedVariants->groupBy('product_id') as $variants)
            @php($product = $variants->first()->product)
            <section class="card overflow-hidden">
                <div class="border-b border-stone-100 bg-stone-50/70 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold">{{ $product->name }}</h3>
                            <p class="text-sm text-stone-500">{{ $product->category?->name ?: \App\Support\UiFormatter::productType($product->type) }}</p>
                        </div>
                        <span class="badge {{ $product->is_active ? 'badge-success' : '' }}">{{ $product->is_active ? 'Activo' : 'Inactivo' }}</span>
                    </div>
                </div>
                <div class="grid gap-4 p-5 lg:grid-cols-2 xl:grid-cols-3">
                    @foreach ($variants as $variant)
                        <article class="rounded-2xl border border-stone-200 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold">{{ $variant->name }}</p>
                                    <p class="mt-1 text-lg font-semibold text-orange-600">{{ \App\Support\UiFormatter::money($variant->price) }}</p>
                                    @if ($variant->recipe)
                                        <p class="mt-2 text-sm font-medium">Disponibilidad estimada: {{ \App\Support\UiFormatter::quantity($variant->sellable_availability->availableQuantity) }}</p>
                                        @if ($variant->sellable_availability->limitingIngredients)
                                            <p class="mt-1 text-xs text-stone-500">Limitante: {{ collect($variant->sellable_availability->limitingIngredients)->pluck('ingredient')->join(', ') }}</p>
                                        @endif
                                    @else
                                        <p class="mt-2 text-sm font-medium text-amber-700">Sin receta configurada</p>
                                    @endif
                                </div>
                                @can($variant->recipe ? 'update' : 'create', $variant->recipe ?: \App\Models\Recipe::class)
                                    <a class="btn-secondary py-2" href="{{ route('recipes.edit', $variant->ulid) }}">
                                        {{ $variant->recipe ? 'Editar receta' : 'Configurar receta' }}
                                    </a>
                                @endcan
                            </div>

                            @if ($variant->recipe)
                                <ul class="mt-4 space-y-2 border-t border-stone-100 pt-4">
                                    @foreach ($variant->recipe->items as $item)
                                        <li class="flex justify-between gap-3 text-sm">
                                            <span class="text-stone-600">{{ $item->ingredient->name }}</span>
                                            <strong>{{ \App\Support\UiFormatter::quantity($item->quantity, $item->ingredient->unit?->symbol) }}</strong>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($variant->recipe->estimated_cost !== null)
                                    <div class="mt-4 rounded-xl bg-amber-50 p-3">
                                        <p class="text-xs font-medium text-amber-800">Costo actual estimado</p>
                                        <p class="mt-1 font-semibold text-amber-950">{{ \App\Support\UiFormatter::money($variant->recipe->estimated_cost) }}</p>
                                        <p class="mt-1 text-[11px] text-amber-700">Usa el costo promedio actual; no representa costo histórico.</p>
                                    </div>
                                @endif
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="empty-state card">No hay productos preparados con variantes.</div>
        @endforelse
    </div>
</section>

<section class="mt-8">
    <div class="mb-4">
        <h2 class="text-xl font-semibold">Venta directa</h2>
        <p class="text-sm text-stone-500">Productos que salen directamente del inventario y no pasan por cocina.</p>
    </div>
    <div class="card grid gap-4 p-5 lg:grid-cols-2 xl:grid-cols-3">
        @forelse ($directVariants as $variant)
            <article class="rounded-2xl border border-stone-200 p-5">
                <p class="font-semibold">{{ $variant->product->name }} · {{ $variant->name }}</p>
                <p class="mt-1 text-lg font-semibold text-orange-600">{{ \App\Support\UiFormatter::money($variant->price) }}</p>
                <p class="mt-2 text-sm font-medium">Disponibilidad: {{ \App\Support\UiFormatter::quantity($variant->sellable_availability->availableQuantity) }}</p>
                <p class="mt-3 rounded-xl bg-stone-50 p-3 text-sm font-medium text-stone-600">Venta directa — no requiere receta</p>
            </article>
        @empty
            <div class="empty-state col-span-full">No hay productos de venta directa.</div>
        @endforelse
    </div>
</section>
@endsection
