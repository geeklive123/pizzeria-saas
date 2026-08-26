@extends('layouts.app')
@section('title', 'Unidades')
@section('heading', 'Unidades')
@section('content')
<div class='page-heading'>
    <div><h1>Unidades de medida</h1><p>Unidades base para ingredientes, compras e inventario de la empresa activa.</p></div>
    @can('create', \App\Models\Unit::class)<div class='flex flex-wrap gap-2'><form method='POST' action='{{ route('units.initialize') }}'>@csrf<button class='btn-secondary'>Preparar unidades estándar</button></form><a class='btn-primary' href='{{ route('units.create') }}'>Nueva unidad</a></div>@endcan
</div>
<div class='mb-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900'>Las unidades estándar compatibles son: gramo (g), kilogramo (kg), mililitro (ml), litro (L) y unidad (u). La inicialización es idempotente.</div>
<div class='card'><div class='table-wrap'><table>
    <thead><tr><th>Nombre</th><th>Símbolo</th><th>Tipo</th><th>Usos</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    @forelse($units as $unit)
        <tr><td class='font-medium'>{{ $unit->name }}</td><td>{{ $unit->symbol }}</td><td>{{ match($unit->type) { \App\Enums\UnitType::Weight => 'Peso', \App\Enums\UnitType::Volume => 'Volumen', \App\Enums\UnitType::Unit => 'Unidad' } }}</td><td>{{ $unit->ingredients_count }} ingredientes · {{ $unit->inventory_items_count }} ítems</td><td><span class='badge {{ $unit->is_active ? 'badge-success' : '' }}'>{{ $unit->is_active ? 'Activa' : 'Inactiva' }}</span></td>
        <td class='flex justify-end gap-2'>@can('update', $unit)<a class='btn-secondary' href='{{ route('units.edit', $unit->id) }}'>Editar</a><form method='POST' action='{{ route('units.toggle', $unit->id) }}'>@csrf<button class='btn-secondary'>{{ $unit->is_active ? 'Desactivar' : 'Activar' }}</button></form>@endcan</td></tr>
    @empty
        <tr><td colspan='6'><div class='empty-state'><p>No hay unidades configuradas.</p>@can('create', \App\Models\Unit::class)<form class='mt-4' method='POST' action='{{ route('units.initialize') }}'>@csrf<button class='btn-primary'>Crear unidades estándar</button></form>@else<p class='mt-2'>Solicita a un propietario o administrador que las configure.</p>@endcan</div></td></tr>
    @endforelse
    </tbody>
</table></div>@if($units->hasPages())<div class='border-t p-4'>{{ $units->links() }}</div>@endif</div>
@endsection
