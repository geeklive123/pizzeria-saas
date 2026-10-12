@extends('layouts.app')
@section('title', $consumption->code)
@section('content')
<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-bold">{{ $consumption->code }}</h1><p class="text-sm text-stone-500">{{ \App\Support\UiFormatter::date($consumption->confirmed_at, true) }} · {{ $consumption->status->label() }}</p></div><a class="btn-secondary" href="{{ route('internal-consumptions.index') }}">Volver</a></div>
    <section class="card grid gap-3 p-5 md:grid-cols-2"><p><strong>Sucursal:</strong> {{ $consumption->branch->name }}</p><p><strong>Responsable:</strong> {{ $consumption->createdBy?->name ?? '—' }}</p><p><strong>Motivo:</strong> {{ $consumption->reason->label() }}</p><p><strong>Costo histórico:</strong>
        @if($consumption->effectiveCostStatus() === 'unknown')
            Desconocido
        @else
            {{ \App\Support\UiFormatter::money($consumption->total_cost) }}{{ $consumption->effectiveCostStatus() === 'partial' ? ' conocido (parcial)' : '' }}
        @endif
    </p>@if($consumption->notes)<p class="md:col-span-2"><strong>Observaciones:</strong> {{ $consumption->notes }}</p>@endif</section>
    <section class="card p-5"><h2 class="font-semibold">Pizzas configuradas</h2><div class="mt-4 space-y-4">@foreach($consumption->items as $item)<article class="rounded-xl border p-4"><p class="font-semibold">{{ \App\Support\UiFormatter::inputQuantity($item->quantity) }} × Pizza {{ \App\Support\UiFormatter::variantName($item->variant_name_snapshot, $item->configuration_snapshot['size_key'] ?? null) }}</p>@foreach($item->configuration_snapshot['sections'] ?? [] as $section)<p class="text-sm">{{ count($item->configuration_snapshot['sections'] ?? []) > 1 ? $section['fraction'].' ' : '' }}{{ $section['product'] }}</p>@endforeach @foreach($item->configuration_snapshot['modifiers'] ?? [] as $modifier)<p class="text-sm">{{ $modifier['type'] === 'remove' ? '−' : '+' }} {{ $modifier['name'] }}</p>@endforeach @if($item->notes)<p class="mt-2 text-sm text-stone-600">Obs: {{ $item->notes }}</p>@endif</article>@endforeach</div></section>
    <section class="card overflow-hidden" id="movements"><div class="border-b p-5"><h2 class="font-semibold">Ingredientes y movimientos</h2></div><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-3">Ingrediente</th><th class="p-3">Cantidad</th><th class="p-3">Costo unitario</th><th class="p-3">Costo total</th><th class="p-3">Movimiento</th></tr></thead><tbody>@foreach($consumption->components as $component)<tr class="border-t"><td class="p-3">{{ $component->inventory_item_name_snapshot }}</td><td class="p-3">{{ \App\Support\UiFormatter::quantity($component->quantity, $component->unit_symbol_snapshot) }}</td><td class="p-3">{{ $component->unit_cost === null ? 'Desconocido' : \App\Support\UiFormatter::money($component->unit_cost) }}</td><td class="p-3">{{ $component->total_cost === null ? 'Desconocido' : \App\Support\UiFormatter::money($component->total_cost) }}</td><td class="p-3 font-mono text-xs">{{ $component->movement->ulid }}@if($component->movement->reversals->isNotEmpty())<span class="block text-red-700">Revertido</span>@endif</td></tr>@endforeach</tbody></table></div></section>
    <div class="flex flex-wrap gap-3">
        @can('print', $consumption)<form method="POST" action="{{ route('internal-consumptions.print', $consumption->ulid) }}">@csrf<input type="hidden" name="idempotency_key" value="internal-consumption:{{ $consumption->ulid }}:{{ $consumption->printAttempts->isEmpty() ? 'original' : 'reprint:'.$printIdempotencyKey }}"><button class="btn-primary">{{ $consumption->printAttempts->isEmpty() ? 'Imprimir comanda' : 'Reimprimir comanda' }}</button></form>@endcan
        <a class="btn-secondary" href="#movements">Ver movimientos</a>
    </div>
    @if($consumption->printAttempts->isNotEmpty())<section class="card p-5"><h2 class="font-semibold">Intentos de impresión</h2><ul class="mt-3 space-y-2 text-sm">@foreach($consumption->printAttempts as $attempt)<li>{{ \App\Support\UiFormatter::date($attempt->attempted_at, true) }} · {{ $attempt->status->value }}{{ $attempt->is_reprint ? ' · reimpresión' : '' }}</li>@endforeach</ul></section>@endif
    @if($consumption->cancellation)<section class="rounded-xl bg-red-50 p-5 text-red-800"><strong>Anulado:</strong> {{ $consumption->cancellation->reason }} · {{ \App\Support\UiFormatter::date($consumption->cancellation->cancelled_at, true) }}</section>
    @elseif(auth()->user()->can('cancel', $consumption))
        <section class="card p-5">
            <h2 class="font-semibold">Anular consumo</h2>
            <p class="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-medium text-amber-900">Esta operación devolverá los ingredientes al inventario. Solo debe utilizarse cuando la pizza no haya sido preparada.</p>
            <p class="mt-2 text-sm text-stone-500">Creará movimientos compensatorios de inventario y conservará el historial.</p>
            <form method="POST" action="{{ route('internal-consumptions.cancel', $consumption->ulid) }}" class="mt-4 space-y-3">
                @csrf
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Motivo de anulación</span>
                    <input class="input w-full" name="reason" minlength="5" maxlength="1000" required value="{{ old('reason') }}">
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input class="mt-1" type="checkbox" name="confirm_not_prepared" value="1" required @checked(old('confirm_not_prepared'))>
                    <span>Confirmo que la pizza no fue preparada y que sus ingredientes no fueron utilizados.</span>
                </label>
                <button class="btn-secondary text-red-700">Anular consumo</button>
            </form>
        </section>
    @endif
</div>
@endsection
