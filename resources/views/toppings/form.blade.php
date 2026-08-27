@extends('layouts.app')
@section('title', $topping->exists ? 'Editar topping' : 'Nuevo topping')
@section('heading', 'Toppings y extras')
@section('content')
@php($existingRules = $topping->exists ? $topping->sizeRules->keyBy('size_key') : collect())
@php($submittedRules = collect(old('size_rules', []))->keyBy('size_key'))
<div class="mx-auto max-w-4xl">
    <div class="page-heading"><div><a class="back-link" href="{{ route('toppings.index') }}">← Volver a toppings</a><h1>{{ $topping->exists ? 'Editar topping' : 'Nuevo topping' }}</h1><p>Esta configuración es administrativa; su aplicación en ventas se habilitará en el bloque siguiente.</p></div></div>
    <form method="POST" action="{{ $topping->exists ? route('toppings.update', $topping->ulid) : route('toppings.store') }}" class="card p-5 sm:p-8">
        @csrf @if($topping->exists)@method('PUT')@endif
        <div class="form-grid">
            <div class="sm:col-span-2"><label class="label" for="name">Nombre</label><input class="input" id="name" name="name" value="{{ old('name', $topping->name) }}" maxlength="255" required></div>
            <div class="sm:col-span-2"><label class="label" for="description">Descripción opcional</label><textarea class="input min-h-24" id="description" name="description">{{ old('description', $topping->description) }}</textarea></div>
            <div><label class="label" for="price_delta">Precio adicional general (Bs)</label><input class="input" id="price_delta" name="price_delta" type="number" min="0" step="0.01" value="{{ old('price_delta', $topping->price_delta ?? '0.00') }}" required></div>
            <div><label class="label" for="sort_order">Orden de visualización</label><input class="input" id="sort_order" name="sort_order" type="number" min="0" step="1" value="{{ old('sort_order', $topping->sort_order ?? 0) }}" required></div>
            <div class="sm:col-span-2"><label class="label" for="inventory_item_ulid">Artículo de inventario opcional</label><select class="input" id="inventory_item_ulid" name="inventory_item_ulid"><option value="">No consume inventario (solo cargo)</option>@foreach($inventoryItems as $item)<option value="{{ $item->ulid }}" @selected(old('inventory_item_ulid', $topping->inventoryItem?->ulid) === $item->ulid)>{{ $item->name }} · {{ $item->unit->name }} ({{ $item->unit->symbol }})</option>@endforeach</select><p class="field-help">Se reutiliza el artículo y su unidad base; aquí no se modifica stock ni el ledger.</p></div>
            <div class="sm:col-span-2"><label class="label" for="default_quantity">Cantidad general opcional</label><input class="input" id="default_quantity" name="default_quantity" type="number" min="0.001" step="0.001" value="{{ old('default_quantity', $topping->quantity) }}"><p class="field-help">Úsala como cantidad común. Si cada tamaño consume distinto, déjala vacía y completa la tabla siguiente.</p></div>
            <label class="sm:col-span-2 flex items-center gap-3 rounded-xl bg-stone-50 p-4"><input class="size-5" type="checkbox" name="is_active" value="1" @checked(old('is_active', $topping->is_active ?? true))><span class="text-sm font-medium">Topping activo</span></label>
        </div>

        <div class="mt-8 border-t border-stone-200 pt-6"><h2 class="text-lg font-semibold">Configuración por tamaño</h2><p class="mt-1 text-sm text-stone-500">El precio vacío usa el precio general. La cantidad solo corresponde cuando seleccionas inventario.</p></div>
        @if($sizes->isEmpty())
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Crea primero una variante de pizza para configurar reglas por tamaño.</div>
        @else
            <div class="mt-4 grid gap-3">
                @foreach($sizes as $index => $size)
                    @php($submitted = $submittedRules->get($size['key']))
                    @php($existing = $existingRules->get($size['key']))
                    <div class="grid gap-3 rounded-xl border border-stone-200 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                        <div><p class="font-semibold">{{ $size['label'] }}</p><p class="text-xs text-stone-500">Clave: {{ $size['key'] }}</p><input type="hidden" name="size_rules[{{ $index }}][size_key]" value="{{ $size['key'] }}"></div>
                        <div><label class="label" for="size-price-{{ $index }}">Precio para este tamaño</label><input class="input" id="size-price-{{ $index }}" name="size_rules[{{ $index }}][price_delta]" type="number" min="0" step="0.01" value="{{ $submitted['price_delta'] ?? $existing?->price_delta }}" placeholder="Usar precio general"></div>
                        <div><label class="label" for="size-quantity-{{ $index }}">Cantidad de consumo</label><input class="input" id="size-quantity-{{ $index }}" name="size_rules[{{ $index }}][quantity]" type="number" min="0.001" step="0.001" value="{{ $submitted['quantity'] ?? $existing?->quantity }}" placeholder="Ej. 20.000"></div>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a class="btn-secondary text-center" href="{{ route('toppings.index') }}">Cancelar</a><button class="btn-primary">Guardar topping</button></div>
    </form>
</div>
@endsection
