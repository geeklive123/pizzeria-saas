@extends('layouts.app')
@section('title', 'Preparaciones internas')
@section('heading', 'Preparaciones internas')
@section('content')
<div class="page-heading"><div><h1>Preparaciones internas</h1><p>Transforma materias primas en ingredientes preparados con trazabilidad de inventario.</p></div>@can('create', \App\Models\Preparation::class)<a class="btn-primary" href="{{ route('preparations.create') }}">+ Nueva preparación</a>@endcan</div>
<div class="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
@forelse($preparations as $preparation)
    @php($availability = $preparation->current_availability)
    <article class="card p-5"><div class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ $preparation->name }}</h2><p class="mt-1 text-sm text-stone-500">Salida: {{ $preparation->outputInventoryItem->name }}</p></div><span class="badge {{ $preparation->is_active ? 'badge-success' : '' }}">{{ $preparation->is_active ? 'Activa' : 'Inactiva' }}</span></div>
        <dl class="mt-5 grid grid-cols-2 gap-3"><div class="rounded-xl bg-stone-50 p-3"><dt class="text-xs text-stone-500">Rendimiento / lote</dt><dd class="mt-1 font-semibold">{{ \App\Support\UiFormatter::quantity($preparation->theoretical_yield, $preparation->unit->symbol) }}</dd></div><div class="rounded-xl bg-orange-50 p-3"><dt class="text-xs text-orange-700">Máximo actual</dt><dd class="mt-1 font-semibold text-orange-950">{{ $availability->maximumLots }} lotes</dd></div></dl>
        @if($availability->limitingComponents)<p class="mt-3 text-xs text-stone-500">Limitante: {{ collect($availability->limitingComponents)->pluck('name')->join(', ') }}</p>@endif
        <a class="btn-secondary mt-5 w-full" href="{{ route('preparations.show', $preparation->ulid) }}">Ver y producir</a>
    </article>
@empty <div class="empty-state card col-span-full">Aún no hay preparaciones internas configuradas.</div> @endforelse
</div>
@endsection
