@extends('layouts.app')
@section('title', 'Consumos internos')
@section('content')
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold">Consumos internos</h1><p class="text-sm text-stone-500">Producción no comercial y su costo histórico.</p></div>@can('create', \App\Models\InternalConsumption::class)<a class="btn-primary" href="{{ route('internal-consumptions.create') }}">Nuevo consumo</a>@endcan</div>
    @if($report)<section class="card p-5"><h2 class="font-semibold">Resumen mensual</h2><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">@foreach($report as $row)<div class="rounded-xl bg-stone-50 p-3"><p class="text-xs font-semibold uppercase text-stone-500">{{ $row['reason']->label() }}</p><p class="mt-2">{{ \App\Support\UiFormatter::inputQuantity($row['pizzas']) }} pizzas</p><p class="font-semibold">{{ \App\Support\UiFormatter::money($row['cost']) }}</p></div>@endforeach</div></section>@endif
    <section class="card overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Código</th><th class="p-3">Fecha</th><th class="p-3">Sucursal</th><th class="p-3">Responsable</th><th class="p-3">Motivo</th><th class="p-3">Pizzas</th><th class="p-3">Costo</th><th class="p-3">Estado</th><th class="p-3"></th></tr></thead><tbody>@forelse($consumptions as $consumption)<tr class="border-t"><td class="p-3 font-semibold">{{ $consumption->code }}</td><td class="p-3">{{ \App\Support\UiFormatter::date($consumption->confirmed_at, true) }}</td><td class="p-3">{{ $consumption->branch->name }}</td><td class="p-3">{{ $consumption->createdBy?->name ?? '—' }}</td><td class="p-3">{{ $consumption->reason->label() }}</td><td class="p-3">{{ \App\Support\UiFormatter::inputQuantity($consumption->pizzaQuantity()) }}</td><td class="p-3">
        @if($consumption->effectiveCostStatus() === 'unknown')
            Desconocido
        @else
            {{ \App\Support\UiFormatter::money($consumption->total_cost) }}{{ $consumption->effectiveCostStatus() === 'partial' ? ' conocido (parcial)' : '' }}
        @endif
    </td><td class="p-3">{{ $consumption->status->label() }}</td><td class="p-3"><a class="font-semibold text-orange-700" href="{{ route('internal-consumptions.show', $consumption->ulid) }}">Ver</a></td></tr>@empty<tr><td class="p-8 text-center text-stone-500" colspan="9">Aún no hay consumos internos.</td></tr>@endforelse</tbody></table></div></section>
    {{ $consumptions->links() }}
</div>
@endsection
