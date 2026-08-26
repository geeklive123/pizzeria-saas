@extends('layouts.app')
@section('title', 'Categorías')
@section('heading', 'Categorías')
@section('content')
<div class='page-heading'>
    <div><h1>Categorías de productos</h1><p>Orden y visibilidad del catálogo de la empresa activa.</p></div>
    @can('create', \App\Models\Category::class)<a class='btn-primary' href='{{ route('categories.create') }}'>Nueva categoría</a>@endcan
</div>
<div class='card'><div class='table-wrap'><table>
    <thead><tr><th>Orden</th><th>Nombre</th><th>Descripción</th><th>Productos</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    @forelse($categories as $category)
        <tr><td>{{ $category->sort_order }}</td><td class='font-medium'>{{ $category->name }}</td><td>{{ $category->description ?: '—' }}</td><td>{{ $category->products_count }}</td><td><span class='badge {{ $category->is_active ? 'badge-success' : '' }}'>{{ $category->is_active ? 'Activa' : 'Inactiva' }}</span></td>
        <td class='flex justify-end gap-2'>@can('update', $category)<a class='btn-secondary' href='{{ route('categories.edit', $category->ulid) }}'>Editar</a><form method='POST' action='{{ route('categories.toggle', $category->ulid) }}'>@csrf<button class='btn-secondary'>{{ $category->is_active ? 'Desactivar' : 'Activar' }}</button></form>@endcan</td></tr>
    @empty
        <tr><td colspan='6'><div class='empty-state'><p>No hay categorías de productos.</p>@can('create', \App\Models\Category::class)<a class='btn-primary mt-4' href='{{ route('categories.create') }}'>Crear primera categoría</a>@else<p class='mt-2'>Solicita a un propietario o administrador que las configure.</p>@endcan</div></td></tr>
    @endforelse
    </tbody>
</table></div>@if($categories->hasPages())<div class='border-t p-4'>{{ $categories->links() }}</div>@endif</div>
@endsection
