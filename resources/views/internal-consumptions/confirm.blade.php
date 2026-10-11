@extends('layouts.app')
@section('title', 'Confirmar consumo interno')
@section('content')
<div class="mx-auto max-w-5xl space-y-5">
    <div><h1 class="text-2xl font-bold">Confirmar consumo interno</h1><p class="text-sm text-stone-500">Verifica configuración, stock y costo antes de descontar inventario.</p></div>
    <section class="card p-5"><p><strong>Motivo:</strong> {{ $reason->label() }}</p>@if($data['notes'] ?? null)<p class="mt-2"><strong>Observación:</strong> {{ $data['notes'] }}</p>@endif</section>
    <section class="card overflow-hidden"><div class="border-b p-5"><h2 class="font-semibold">Pizzas</h2></div><div class="divide-y">@foreach($preview['items'] as $item)<div class="p-5"><p class="font-semibold">{{ \App\Support\UiFormatter::inputQuantity($item['quantity']) }} × Pizza {{ \App\Support\UiFormatter::variantName($item['composition']['primary_variant']->name, $item['composition']['snapshot']['size_key']) }}</p><ul class="mt-2 text-sm text-stone-600">@foreach($item['composition']['snapshot']['sections'] as $section)<li>{{ count($item['composition']['snapshot']['sections']) > 1 ? $section['fraction'].' ' : '' }}{{ $section['product'] }}</li>@endforeach @foreach($item['composition']['snapshot']['modifiers'] as $modifier)<li>{{ $modifier['type'] === 'remove' ? '−' : '+' }} {{ $modifier['name'] }}</li>@endforeach</ul></div>@endforeach</div></section>
    <section class="card overflow-hidden"><div class="border-b p-5"><h2 class="font-semibold">Ingredientes requeridos y disponibilidad</h2></div><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Ingrediente</th><th class="p-3">Requerido</th><th class="p-3">Disponible</th><th class="p-3">Costo estimado</th></tr></thead><tbody>@foreach($preview['requirements'] as $requirement)<tr class="border-t"><td class="p-3">{{ $requirement['inventory_item']->name }}</td><td class="p-3">{{ \App\Support\UiFormatter::quantity($requirement['quantity'], $requirement['inventory_item']->unit?->symbol) }}</td><td class="p-3">{{ \App\Support\UiFormatter::inputQuantity($requirement['available_quantity']) }}</td><td class="p-3">{{ $requirement['total_cost'] === null ? 'Desconocido' : \App\Support\UiFormatter::money($requirement['total_cost']) }}</td></tr>@endforeach</tbody></table></div><div class="border-t p-5">
        @if($preview['cost_status'] === 'unknown')
            <strong>Costo estimado: Desconocido</strong><p class="text-sm text-amber-700">Ningún ingrediente tiene costo promedio conocido.</p>
        @else
            <strong>Costo estimado conocido: {{ \App\Support\UiFormatter::money($preview['estimated_total_cost']) }}</strong>
            @if($preview['cost_status'] === 'partial')<p class="text-sm text-amber-700">Estimación parcial: existen ingredientes sin costo promedio conocido.</p>@endif
        @endif
    </div></section>
    <div class="flex gap-3"><a class="btn-secondary" href="{{ route('internal-consumptions.create') }}">Editar</a><form method="POST" action="{{ route('internal-consumptions.store') }}">@csrf<input type="hidden" name="payload" value="{{ $payload }}"><button class="btn-primary">Confirmar y descontar inventario</button></form></div>
</div>
@endsection
