@extends('layouts.app')

@section('title', 'Nuevo consumo interno')

@section('content')
<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold">Nuevo consumo interno</h1><p class="text-sm text-stone-500">Producción no comercial. No genera venta, pago ni movimiento de caja.</p></div>
        <a class="btn-secondary" href="{{ route('internal-consumptions.index') }}">Volver</a>
    </div>

    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('internal-consumptions.preview') }}" class="space-y-5" data-internal-consumption-form>
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        <section class="card grid gap-4 p-5 md:grid-cols-2">
            <div><label class="label" for="reason">Motivo</label><select class="input" id="reason" name="reason" required>@foreach($reasons as $reason)<option value="{{ $reason->value }}">{{ $reason->label() }}</option>@endforeach</select></div>
            <div><label class="label" for="notes">Observación general</label><input class="input" id="notes" name="notes" maxlength="2000" value="{{ old('notes') }}"></div>
        </section>

        <div class="space-y-4" data-internal-pizza-list></div>
        <div class="flex flex-wrap gap-3"><button class="btn-secondary" type="button" data-add-internal-pizza>+ Agregar otra pizza</button><button class="btn-primary" type="submit">Revisar consumo</button></div>
    </form>

    <template data-internal-pizza-template>
        <section class="card space-y-4 p-5" data-internal-pizza>
            <div class="flex items-center justify-between"><h2 class="text-lg font-semibold">Pizza <span data-pizza-number></span></h2><button class="text-sm font-semibold text-red-700" type="button" data-remove-internal-pizza>Quitar</button></div>
            <div class="grid gap-4 md:grid-cols-3">
                <div><label class="label">Tamaño</label><select class="input" data-internal-size>@foreach($pizzaVariants as $sizeKey => $variants)<option value="{{ $sizeKey }}">{{ \App\Support\UiFormatter::variantName($variants->first()->name, $sizeKey) }}</option>@endforeach</select></div>
                <div><label class="label">Cantidad</label><input class="input" type="number" min="1" max="99" name="items[__INDEX__][quantity]" value="1" required></div>
                <div><label class="label">Entrega</label><select class="input" name="items[__INDEX__][fulfillment_type]"><option value="dine_in">Comer aquí</option><option value="takeaway">Para llevar (incluye packaging)</option></select></div>
            </div>
            <div><label class="label">Cantidad de sabores</label><select class="input max-w-xs" data-internal-section-count><option value="1">1 sabor</option><option value="2">2 sabores</option><option value="3">3 sabores</option><option value="4">4 sabores</option></select></div>
            @foreach([0,1,2,3] as $section)
                <div data-internal-section="{{ $section }}" @if($section) hidden @endif><label class="label">Sabor {{ $section + 1 }}</label><select class="input" name="items[__INDEX__][sections][{{ $section }}][variant]" data-internal-variant @if(!$section) required @endif></select></div>
            @endforeach
            @foreach($pizzaVariants as $sizeKey => $variants)
                <template data-internal-options="{{ $sizeKey }}"><option value="">Selecciona un sabor</option>@foreach($variants as $variant)<option value="{{ $variant->ulid }}">{{ $variant->product->name }} · {{ \App\Support\UiFormatter::variantName($variant->name, $sizeKey) }}</option>@endforeach</template>
            @endforeach

            @if($toppingOptions->isNotEmpty())
                <fieldset><legend class="label">Toppings / extras</legend><div class="grid gap-2 sm:grid-cols-2">@foreach($toppingOptions as $option)<label class="rounded-xl border border-stone-200 p-3"><input type="checkbox" name="items[__INDEX__][toppings][]" value="{{ $option->ulid }}"> + {{ $option->name }}</label>@endforeach</div></fieldset>
            @endif
            @if($modifierOptions->isNotEmpty())
                <fieldset><legend class="label">Extras y removidos de receta</legend><div class="grid gap-2 sm:grid-cols-2">@foreach($modifierOptions as $modifierIndex => $option)<div class="rounded-xl border border-stone-200 p-3"><label><input type="checkbox" name="items[__INDEX__][modifiers][{{ $modifierIndex }}][option]" value="{{ $option->ulid }}"> {{ $option->type->value === 'remove' ? '−' : '+' }} {{ $option->name }}</label><select class="input mt-2" name="items[__INDEX__][modifiers][{{ $modifierIndex }}][section_position]"><option value="">Pizza completa</option><option value="1">Sabor 1</option><option value="2">Sabor 2</option><option value="3">Sabor 3</option><option value="4">Sabor 4</option></select></div>@endforeach</div></fieldset>
            @endif
            <div><label class="label">Observación de cocina</label><input class="input" name="items[__INDEX__][notes]" maxlength="500" placeholder="Bien cocida, cortar en 8…"></div>
        </section>
    </template>
</div>
@endsection
