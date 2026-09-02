<div class="grid gap-3 rounded-2xl border border-stone-200 p-4 sm:grid-cols-[1fr_.45fr_auto]" data-row>
    <div>
        <label class="label">Ingrediente / materia prima</label>
        <select class="input" name="components[{{ $index }}][inventory_item_id]" required>
            <option value="">Selecciona…</option>
            @foreach($items as $item)
                <option value="{{ $item->id }}" @selected((string)($row['inventory_item_id'] ?? '') === (string)$item->id)>{{ $item->name }} · {{ $item->unit->symbol }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label">Cantidad por lote</label>
        <input class="input" name="components[{{ $index }}][quantity]" value="{{ $row['quantity'] ?? '' }}" inputmode="decimal" required>
    </div>
    <div class="flex items-end pb-2"><button type="button" class="text-sm font-medium text-red-600" data-remove-row>Quitar</button></div>
</div>
