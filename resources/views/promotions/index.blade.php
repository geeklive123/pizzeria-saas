@extends('layouts.app')
@section('title', 'Promociones')
@section('heading', 'Promociones')
@section('content')
<div class="page-heading">
    <div><h1>Promociones</h1><p>Ofertas vendibles compuestas por artículos del inventario existente.</p></div>
    @can('create', \App\Models\Promotion::class)<a class="btn-primary" href="{{ route('promotions.create') }}">+ Nueva promoción</a>@endcan
</div>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Promoción</th><th>Precio</th><th>Componentes</th><th>Vigencia</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    @forelse($promotions as $promotion)
        <tr>
            <td><p class="font-semibold">{{ $promotion->productVariant->product->name }}</p><p class="text-xs text-stone-500">Categoría Promociones</p></td>
            <td>{{ \App\Support\UiFormatter::money($promotion->productVariant->price) }}</td>
            <td>@foreach($promotion->components as $component)<p class="text-sm">{{ $component->inventoryItem->name }} × {{ \App\Support\UiFormatter::quantity($component->quantity, $component->inventoryItem->unit->symbol) }}</p>@endforeach</td>
            <td><p class="text-sm">{{ $promotion->starts_at ? \App\Support\UiFormatter::date($promotion->starts_at, true) : 'Sin inicio' }}</p><p class="text-sm">{{ $promotion->ends_at ? \App\Support\UiFormatter::date($promotion->ends_at, true) : 'Sin fin' }}</p></td>
            <td><span class="badge {{ $promotion->isCurrentlyActive() ? 'badge-success' : '' }}">{{ $promotion->isCurrentlyActive() ? 'Disponible' : ($promotion->is_active ? 'Fuera de vigencia' : 'Inactiva') }}</span></td>
            <td class="text-right">@can('update', $promotion)<div class="flex justify-end gap-2"><a class="link" href="{{ route('promotions.edit', $promotion->ulid) }}">Editar</a><form method="POST" action="{{ route('promotions.toggle', $promotion->ulid) }}">@csrf<button class="link">{{ $promotion->is_active ? 'Desactivar' : 'Activar' }}</button></form></div>@endcan</td>
        </tr>
    @empty
        <tr><td colspan="6"><div class="empty-state">No hay promociones registradas.</div></td></tr>
    @endforelse
    </tbody>
</table></div>@if($promotions->hasPages())<div class="border-t border-stone-100 p-4">{{ $promotions->links() }}</div>@endif</div>
@endsection
