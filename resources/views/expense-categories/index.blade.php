@extends('layouts.app')
@section('title', 'Categorías de gasto')
@section('heading', 'Categorías de gasto')
@section('content')
<div class="page-heading"><div><h1>Categorías de gasto</h1><p>Clasificación administrativa por empresa.</p></div><a class="btn-primary" href="{{ route('expense-categories.create') }}">Nueva categoría</a></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Descripción</th><th>Gastos</th><th>Estado</th><th></th></tr></thead><tbody>@forelse($categories as $category)<tr><td class="font-medium">{{ $category->name }}</td><td>{{ $category->description ?: '—' }}</td><td>{{ $category->expenses_count }}</td><td><span class="badge {{ $category->is_active ? 'badge-success' : '' }}">{{ $category->is_active ? 'Activa' : 'Inactiva' }}</span></td><td class="flex justify-end gap-2"><a class="btn-secondary" href="{{ route('expense-categories.edit',$category->ulid) }}">Editar</a><form method="POST" action="{{ route('expense-categories.toggle',$category->ulid) }}">@csrf<button class="btn-secondary">{{ $category->is_active ? 'Desactivar' : 'Activar' }}</button></form></td></tr>@empty<tr><td colspan="5"><div class="empty-state">No hay categorías.</div></td></tr>@endforelse</tbody></table></div>@if($categories->hasPages())<div class="border-t p-4">{{ $categories->links() }}</div>@endif</div>
@endsection
