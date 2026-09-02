@extends('layouts.app')
@section('title', $preparation->name)
@section('heading', 'Preparaciones internas')
@section('content')
<div class="page-heading"><div><a class="back-link" href="{{ route('preparations.index') }}">← Volver a preparaciones</a><h1>{{ $preparation->name }}</h1><p>Produce {{ $preparation->outputInventoryItem->name }} en {{ $preparation->unit->name }}.</p></div>@can('update', $preparation)<a class="btn-secondary" href="{{ route('preparations.edit', $preparation->ulid) }}">Editar fórmula</a>@endcan</div>

<div class="grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
<div class="space-y-6">
    <section class="card p-5 sm:p-7"><div class="flex items-start justify-between gap-4"><div><h2 class="card-title">Fórmula por lote</h2><p class="card-subtitle">Rendimiento teórico: {{ \App\Support\UiFormatter::quantity($preparation->theoretical_yield, $preparation->unit->symbol) }}</p></div><span class="badge {{ $preparation->is_active ? 'badge-success' : '' }}">{{ $preparation->is_active ? 'Activa' : 'Inactiva' }}</span></div>
        <div class="mt-5 table-wrap"><table><thead><tr><th>Componente</th><th>Cantidad</th></tr></thead><tbody>@foreach($preparation->components as $component)<tr><td class="font-medium">{{ $component->inventoryItem->name }}</td><td>{{ \App\Support\UiFormatter::quantity($component->quantity, $component->inventoryItem->unit->symbol) }}</td></tr>@endforeach</tbody></table></div>
    </section>

    <section class="card border-orange-200 bg-orange-50/40 p-5 sm:p-7" data-current-availability>
        <p class="text-xs font-bold uppercase tracking-wider text-orange-700">Disponibilidad actual</p>
        @if($currentAvailability->maximumLots > 0)
            <h2 class="mt-2 text-xl font-semibold">Puedes preparar hasta {{ $currentAvailability->maximumLots }} lotes de {{ $preparation->name }}.</h2>
            <p class="mt-3 text-sm">Producción estimada: <strong>{{ \App\Support\UiFormatter::quantity($currentAvailability->estimatedYield, $preparation->unit->symbol) }}</strong></p>
            <p class="mt-1 text-sm">Ingrediente limitante: <strong>{{ collect($currentAvailability->limitingComponents)->pluck('name')->join(', ') }}</strong></p>
        @else
            <h2 class="mt-2 text-xl font-semibold text-red-800">No hay stock suficiente para preparar un lote.</h2>
            <p class="mt-2 text-sm text-red-700">Faltan: {{ collect($currentAvailability->limitingComponents)->pluck('name')->join(', ') }}.</p>
        @endif
    </section>

    @can('produce', $preparation)
    <section class="card p-5 sm:p-7" data-preparation-production data-maximum-lots="{{ $currentAvailability->maximumLots }}" data-yield-per-lot="{{ $preparation->theoretical_yield }}">
        <h2 class="card-title">Registrar producción</h2><p class="card-subtitle">La confirmación vuelve a validar stock, lotes, vencimientos y reservas dentro de la transacción.</p>
        <form method="POST" action="{{ route('preparations.produce', $preparation->ulid) }}" class="mt-5 space-y-5" data-production-form>@csrf
            <div class="form-grid"><div><label class="label" for="lots">Cantidad de lotes</label><input class="input" id="lots" name="lots" type="number" min="1" max="{{ max(1, $currentAvailability->maximumLots) }}" value="{{ old('lots', 1) }}" required data-production-lots><p class="field-help">Máximo disponible: {{ $currentAvailability->maximumLots }}</p></div><div><label class="label" for="actual_yield">Rendimiento real (opcional, {{ $preparation->unit->symbol }})</label><input class="input" id="actual_yield" name="actual_yield" value="{{ old('actual_yield') }}" inputmode="decimal" placeholder="Usará el rendimiento teórico"><p class="field-help">Si lo dejas vacío se usarán <span data-production-output>{{ \App\Support\UiFormatter::quantity($preparation->theoretical_yield, $preparation->unit->symbol) }}</span>.</p></div></div>
            <div class="rounded-2xl bg-stone-50 p-4"><p class="text-sm font-semibold">Consumirá:</p><ul class="mt-3 space-y-2">@foreach($preparation->components as $component)<li class="flex justify-between gap-3 text-sm"><span>{{ $component->inventoryItem->name }}</span><strong><span data-production-quantity data-per-lot="{{ $component->quantity }}">{{ \App\Support\UiFormatter::quantity($component->quantity) }}</span> {{ $component->inventoryItem->unit->symbol }}</strong></li>@endforeach</ul><p class="mt-4 border-t border-stone-200 pt-4 text-sm">Producirá aproximadamente: <strong data-production-output>{{ \App\Support\UiFormatter::quantity($preparation->theoretical_yield, $preparation->unit->symbol) }}</strong></p></div>
            <p class="text-sm font-medium text-red-700" hidden data-production-error tabindex="-1"></p>
            <button class="btn-primary w-full" type="submit" @disabled(!$preparation->is_active || $currentAvailability->maximumLots < 1) data-production-submit>Confirmar producción</button>
        </form>
    </section>
    @endcan
</div>

<section class="card self-start"><div class="card-header"><div><h2 class="card-title">Historial de producción</h2><p class="card-subtitle">Últimos registros de esta sucursal.</p></div></div>
    <div class="divide-y divide-stone-100">@forelse($preparation->productions as $production)<article class="p-5"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ $production->lots }} {{ $production->lots === 1 ? 'lote' : 'lotes' }} · {{ \App\Support\UiFormatter::quantity($production->actual_yield, $preparation->unit->symbol) }}</p><p class="mt-1 text-xs text-stone-500">{{ \App\Support\UiFormatter::date($production->produced_at, true) }} · {{ $production->createdBy?->name ?? 'Usuario histórico' }}</p></div><span class="badge {{ $production->reversed_at ? 'badge-danger' : 'badge-success' }}">{{ $production->reversed_at ? 'Revertida' : 'Confirmada' }}</span></div>
        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm"><div><dt class="text-xs text-stone-500">Teórico / diferencia</dt><dd>{{ \App\Support\UiFormatter::quantity($production->theoretical_yield) }} / {{ \App\Support\UiFormatter::quantity($production->yield_variance) }}</dd></div><div><dt class="text-xs text-stone-500">Costo total / unitario</dt><dd>{{ \App\Support\UiFormatter::money($production->total_cost) }} / {{ \App\Support\UiFormatter::money($production->unit_cost) }}</dd></div></dl>
        @if(!$production->reversed_at) @can('reverse', $preparation)<form class="mt-4 flex gap-2" method="POST" action="{{ route('preparations.productions.reverse', [$preparation->ulid, $production->ulid]) }}">@csrf<input class="input" name="reason" placeholder="Motivo de reversión" required><button class="btn-danger" type="submit">Revertir</button></form>@endcan @else <p class="mt-3 text-xs text-red-700">{{ $production->reversal_reason }}</p> @endif
    </article>@empty<div class="empty-state">Todavía no se registraron producciones en esta sucursal.</div>@endforelse</div>
</section>
</div>
@endsection
