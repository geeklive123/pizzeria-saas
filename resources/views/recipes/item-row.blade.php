<div class="grid gap-3 rounded-2xl border border-stone-200 p-4 sm:grid-cols-[1fr_.55fr_.45fr_auto]" data-row>
    <div>
        <label class="label">Ingrediente</label>
        <select class="input" name="items[{{ $index }}][ingredient_id]" required>
            <option value="">Selecciona…</option>
            @foreach ($ingredients as $ingredient)
                <option value="{{ $ingredient->id }}" @selected((string) ($row['ingredient_id'] ?? '') === (string) $ingredient->id)>{{ $ingredient->name }} · {{ $ingredient->unit->symbol }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label">Uso en pizza</label>
        <select class="input" name="items[{{ $index }}][component_type]">
            <option value="base" @selected(($row['component_type'] ?? 'topping') === 'base')>Base completa</option>
            <option value="topping" @selected(($row['component_type'] ?? 'topping') === 'topping')>Ingrediente del sabor</option>
        </select>
    </div>
    <div>
        <label class="label">Cantidad</label>
        <input class="input" name="items[{{ $index }}][quantity]" value="{{ $row['quantity'] ?? '' }}" inputmode="decimal" placeholder="0,000" required>
    </div>
    <div class="flex items-end pb-2">
        <button type="button" class="text-sm font-medium text-red-600" data-remove-row>Quitar</button>
    </div>
</div>
