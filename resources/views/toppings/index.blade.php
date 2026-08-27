@extends('layouts.app')
@section('title', 'Toppings y extras')
@section('heading', 'Toppings y extras')
@section('content')
<div class="page-heading">
    <div><a class="back-link" href="{{ route('products.index') }}">← Volver a productos</a><h1>Toppings y extras</h1><p>Configura cargos opcionales y su consumo previsto. Todavía no se aplican en ventas.</p></div>
    @can('create', \App\Models\ModifierOption::class)<a class="btn-primary" href="{{ route('toppings.create') }}">+ Nuevo topping</a>@endcan
</div>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Orden</th><th>Topping</th><th>Precio adicional</th><th>Inventario</th><th>Por tamaño</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    @forelse($toppings as $topping)
        <tr>
            <td>{{ $topping->sort_order }}</td>
            <td><p class="font-semibold">{{ $topping->name }}</p><p class="max-w-xs text-xs text-stone-500">{{ $topping->description ?: 'Sin descripción' }}</p></td>
            <td>{{ \App\Support\UiFormatter::money($topping->price_delta) }}</td>
            <td>
                @if($topping->inventoryItem)
                    <p class="font-medium">{{ $topping->inventoryItem->name }}</p>
                    <p class="text-xs text-stone-500">{{ $topping->quantity ? \App\Support\UiFormatter::quantity($topping->quantity, $topping->inventoryItem->unit->symbol).' general' : 'Cantidad definida por tamaño' }}</p>
                @else
                    <span class="text-sm text-stone-500">Solo cargo adicional</span>
                @endif
            </td>
            <td><div class="flex flex-wrap gap-1">@forelse($topping->sizeRules as $rule)<span class="badge">{{ $rule->size_key }}@if($rule->quantity) · {{ \App\Support\UiFormatter::quantity($rule->quantity, $topping->inventoryItem?->unit?->symbol) }}@endif @if($rule->price_delta !== null)· {{ \App\Support\UiFormatter::money($rule->price_delta) }}@endif</span>@empty<span class="text-sm text-stone-500">Sin reglas</span>@endforelse</div></td>
            <td><span class="badge {{ $topping->is_active ? 'badge-success' : '' }}">{{ $topping->is_active ? 'Activo' : 'Inactivo' }}</span></td>
            <td class="text-right">@can('update', $topping)<div class="flex justify-end gap-2"><a class="link" href="{{ route('toppings.edit', $topping->ulid) }}">Editar</a><form method="POST" action="{{ route('toppings.toggle', $topping->ulid) }}">@csrf<button class="link">{{ $topping->is_active ? 'Desactivar' : 'Activar' }}</button></form></div>@endcan</td>
        </tr>
    @empty
        <tr><td colspan="7"><div class="empty-state">No hay toppings registrados.</div></td></tr>
    @endforelse
    </tbody>
</table></div>@if($toppings->hasPages())<div class="border-t border-stone-100 p-4">{{ $toppings->links() }}</div>@endif</div>
@endsection
