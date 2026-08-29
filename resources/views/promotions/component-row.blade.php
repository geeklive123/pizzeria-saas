<div class="grid gap-3 rounded-xl border border-stone-200 p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end" data-row>
    <div>
        <label class="label" for="component-item-{{ $index }}">Artículo de inventario</label>
        <select class="input" id="component-item-{{ $index }}" name="components[{{ $index }}][inventory_item_ulid]" required>
            <option value="">Seleccionar…</option>
            @foreach ($inventoryItems as $inventoryItem)
                <option value="{{ $inventoryItem->ulid }}" @selected(($component['inventory_item_ulid'] ?? null) === $inventoryItem->ulid)>{{ $inventoryItem->name }} · {{ $inventoryItem->unit->symbol }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="component-quantity-{{ $index }}">Consumo por promoción</label>
        <input class="input" id="component-quantity-{{ $index }}" name="components[{{ $index }}][quantity]" type="number" min="0.001" step="0.001" value="{{ $component['quantity'] ?? '' }}" required>
    </div>
    <button class="btn-secondary" type="button" data-remove-row>Quitar</button>
</div>
