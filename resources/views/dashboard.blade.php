@extends('layouts.app')
@section('title', 'Inicio')
@section('heading', 'Inicio')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-3 sm:flex-row sm:items-end"><div><p class="text-sm font-medium text-orange-600">Resumen del día</p><h1 class="mt-1 text-3xl font-semibold tracking-tight">Hola, {{ str(auth()->user()->name)->before(' ') }}</h1><p class="mt-2 text-stone-500">Así está la operación de {{ request()->attributes->get('branch')->name }}.</p></div><p class="text-sm text-stone-500">{{ now('America/La_Paz')->translatedFormat('l, d \d\e F') }}</p></div>
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="metric-card"><span class="metric-icon bg-orange-100 text-orange-700">Bs</span><p class="metric-label">Inventario total</p><p class="metric-value">{{ \App\Support\UiFormatter::money($inventoryValue) }}</p><p class="metric-help">Valorización aproximada actual</p></div>
    <div class="metric-card"><span class="metric-icon bg-amber-100 text-amber-700">◇</span><p class="metric-label">Ingredientes</p><p class="metric-value">{{ $activeIngredients }}</p><p class="metric-help">Ingredientes activos</p></div>
    <div class="metric-card"><span class="metric-icon bg-sky-100 text-sky-700">◫</span><p class="metric-label">Productos</p><p class="metric-value">{{ $activeProducts }}</p><p class="metric-help">Productos activos</p></div>
    <div class="metric-card"><span class="metric-icon bg-emerald-100 text-emerald-700">▦</span><p class="metric-label">Artículos inventariables</p><p class="metric-value">{{ $inventoryItems->count() }}</p><p class="metric-help">Con y sin existencia</p></div>
</section>
<section class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><div class="metric-card"><p class="metric-label">Mesas ocupadas</p><p class="metric-value">{{ $occupiedTables }}</p></div><div class="metric-card"><p class="metric-label">Pedidos abiertos</p><p class="metric-value">{{ $openOrders }}</p></div><div class="metric-card"><p class="metric-label">En cocina</p><p class="metric-value">{{ $kitchenItems }}</p></div><div class="metric-card"><p class="metric-label">Productos listos</p><p class="metric-value">{{ $readyItems }}</p></div><div class="metric-card"><p class="metric-label">Alertas inventario</p><p class="metric-value">{{ $inventoryAlerts }}</p></div></section>
<div class="mt-6 grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
    <section class="card"><div class="card-header"><div><h2 class="card-title">Últimos movimientos</h2><p class="card-subtitle">Entradas y salidas recientes</p></div><a class="link" href="{{ route('inventory.movements') }}">Ver historial</a></div><div class="divide-y divide-stone-100">@forelse($recentMovements as $movement)<div class="flex items-center gap-4 px-5 py-4"><span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $movement->direction() > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">{{ $movement->direction() > 0 ? '↓' : '↑' }}</span><div class="min-w-0 flex-1"><p class="truncate font-medium">{{ $movement->inventoryItem->name }}</p><p class="text-xs text-stone-500">{{ \App\Support\UiFormatter::movement($movement->type) }} · {{ \App\Support\UiFormatter::date($movement->occurred_at, true) }}</p></div><p class="font-semibold {{ $movement->direction() > 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $movement->direction() > 0 ? '+' : '−' }}{{ \App\Support\UiFormatter::quantity($movement->quantity, $movement->inventoryItem->unit->symbol) }}</p></div>@empty<div class="empty-state">Todavía no hay movimientos.</div>@endforelse</div></section>
    <section class="card"><div class="card-header"><div><h2 class="card-title">Necesitan atención</h2><p class="card-subtitle">Stock, caducidad y capacidad de recetas</p></div><a class="link" href="{{ route('inventory.index') }}">Ver inventario</a></div><div class="divide-y divide-stone-100">
        @forelse($inventoryAttention as $item)
            <div class="flex flex-col items-start gap-2 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div class="min-w-0">
                    <p class="font-medium">{{ $item->name }}</p>
                    <p class="text-xs {{ $item->expired_quantity !== '0.000' ? 'text-red-700' : 'text-amber-700' }}">
                        @if($item->expired_quantity !== '0.000')
                            Vencido — {{ \App\Support\UiFormatter::quantity($item->expired_quantity, $item->unit->symbol) }} requiere retiro
                        @elseif($item->expiration_status === \App\Enums\InventoryBatchStatus::ExpiringSoon && $item->next_expiration_at)
                            Próximo a vencer — {{ \App\Support\UiFormatter::date($item->next_expiration_at) }}
                        @else
                            {{ \App\Support\UiFormatter::stockStatus($item->stock_status) }} — disponible {{ \App\Support\UiFormatter::quantity($item->available_quantity, $item->unit->symbol) }}
                            @if($item->expiration_description)
                                · {{ $item->expiration_description }}
                            @endif
                        @endif
                    </p>
                </div>
                <a class="link shrink-0" href="{{ route('inventory.show', $item->ulid) }}">Ver inventario</a>
            </div>
        @empty
            @if($recipeAttention->isEmpty())
                <div class="empty-state">Todo está en orden.</div>
            @endif
        @endforelse
        @foreach($recipeAttention as $variant)
            <div class="flex flex-col items-start gap-2 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div class="min-w-0">
                    <p class="font-medium">{{ $variant->product->name }} · {{ \App\Support\UiFormatter::variantName($variant->name, $variant->size_key) }}</p>
                    <p class="text-xs text-amber-700">
                        @if($variant->sellable_availability->mode === 'recipe_pending')
                            Receta incompleta
                        @else
                            Solo pueden prepararse {{ (int) $variant->sellable_availability->availableQuantity }} · Limita: {{ $variant->limiting_ingredients_label }}
                        @endif
                    </p>
                </div>
                <a class="link shrink-0" href="{{ route('recipes.edit', $variant->ulid) }}">Ver receta</a>
            </div>
        @endforeach
    </div></section>
</div>
<section class="card mt-6"><div class="card-header"><div><h2 class="card-title">Compras recientes</h2><p class="card-subtitle">Documentos de la sucursal activa</p></div><a class="link" href="{{ route('purchases.index') }}">Ver compras</a></div><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Proveedor</th><th>Documento</th><th>Estado</th><th>Usuario</th></tr></thead><tbody>@forelse($recentPurchases as $purchase)<tr><td>{{ \App\Support\UiFormatter::date($purchase->purchased_at) }}</td><td>{{ $purchase->supplier_name ?: 'Sin proveedor' }}</td><td>{{ $purchase->document_number ?: '—' }}</td><td><span class="badge">{{ \App\Support\UiFormatter::purchaseStatus($purchase->status) }}</span></td><td>{{ $purchase->createdBy?->name ?: 'Sistema' }}</td></tr>@empty<tr><td colspan="5" class="text-center text-stone-500">No hay compras registradas.</td></tr>@endforelse</tbody></table></div></section>
@endsection
