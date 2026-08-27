@extends('layouts.app')
@section('title', 'Productos')
@section('heading', 'Productos')
@section('content')
@can('viewAny', \App\Models\ModifierOption::class)
<div class='mb-4 flex justify-end'><a class='btn-secondary' href='{{ route('toppings.index') }}'>Administrar toppings / extras</a></div>
@endcan
<div class="page-heading"><div><h1>Productos</h1><p>Administra el menú, sus variantes y precios.</p></div>@can('create', \App\Models\Product::class)<a class="btn-primary" href="{{ route('products.create') }}">+ Nuevo producto</a>@endcan</div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Producto</th><th>Categoría</th><th>Tipo</th><th>Variantes y precios</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($products as $product)<tr><td><p class="font-semibold">{{ $product->name }}</p><p class="max-w-xs truncate text-xs text-stone-500">{{ $product->description ?: 'Sin descripción' }}</p></td><td>{{ $product->category?->name ?: 'Sin categoría' }}</td><td>{{ \App\Support\UiFormatter::productType($product->type) }}</td><td><div class="flex max-w-2xl flex-wrap gap-1.5">@foreach($product->variants->sortBy('sort_order') as $variant)<span class="rounded-lg bg-stone-100 px-2.5 py-1 text-xs"><strong>{{ $variant->name }}</strong> · {{ \App\Support\UiFormatter::money($variant->price) }} · @if(!$variant->requires_preparation) Venta directa — no requiere receta @elseif($variant->recipe) Receta configurada @else <span class="font-semibold text-amber-700">Receta pendiente</span> @endif · Disponibles: {{ \App\Support\UiFormatter::quantity($variant->sellable_availability->availableQuantity) }}@if($variant->sellable_availability->mode==='recipe' && $variant->sellable_availability->limitingIngredients) · Limita: {{ collect($variant->sellable_availability->limitingIngredients)->pluck('ingredient')->join(', ') }}@endif</span>@endforeach</div></td><td><span class="badge {{ $product->is_active ? 'badge-success' : '' }}">{{ $product->is_active ? 'Activo' : 'Inactivo' }}</span></td><td class="text-right space-y-2">@if($product->type === \App\Enums\ProductType::Pizza)<a class="link block" href="{{ route('recipes.create', ['product' => $product->ulid]) }}">Configurar recetas</a>@endif @can('update', $product)<a class="link block" href="{{ route('products.edit', $product->ulid) }}">Editar</a>@endcan</td></tr>
@empty<tr><td colspan="6"><div class="empty-state">No hay productos registrados.</div></td></tr>@endforelse
</tbody></table></div>@if($products->hasPages())<div class="border-t border-stone-100 p-4">{{ $products->links() }}</div>@endif</div>
@endsection
