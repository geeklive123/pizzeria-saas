<div class="rounded-xl border border-orange-200 bg-orange-50/30 p-4" data-row>
    <input type="hidden" name="selection_groups[{{ $index }}][ulid]" value="{{ $group['ulid'] ?? '' }}">
    <div class="grid gap-3 sm:grid-cols-3">
        <div>
            <label class="label">Tipo</label>
            <input class="input bg-stone-50" value="Pizza seleccionable" readonly>
        </div>
        <div>
            <label class="label" for="group-category-{{ $index }}">Categoría permitida</label>
            <select class="input" id="group-category-{{ $index }}" name="selection_groups[{{ $index }}][category_ulid]" required>
                <option value="">Seleccionar…</option>
                @foreach($pizzaCategories as $category)
                    <option value="{{ $category->ulid }}" @selected(($group['category_ulid'] ?? null) === $category->ulid)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="group-size-{{ $index }}">Tamaño</label>
            <select class="input" id="group-size-{{ $index }}" name="selection_groups[{{ $index }}][size_key]" required>
                <option value="personal" @selected(($group['size_key'] ?? null) === 'personal')>Personal</option>
                <option value="mediana" @selected(($group['size_key'] ?? null) === 'mediana')>Mediana</option>
                <option value="familiar" @selected(($group['size_key'] ?? null) === 'familiar')>Grande (Familiar)</option>
            </select>
        </div>
        <div>
            <label class="label" for="group-quantity-{{ $index }}">Cantidad de pizzas</label>
            <input class="input" id="group-quantity-{{ $index }}" name="selection_groups[{{ $index }}][quantity]" type="number" min="1" max="12" value="{{ $group['quantity'] ?? 1 }}" required>
        </div>
        @foreach([
            'allow_repeated_flavors' => 'Permitir repetir sabores',
            'allow_fusions' => 'Permitir fusionadas',
            'allow_extras' => 'Permitir extras',
            'allow_removals' => 'Permitir removidos',
        ] as $field => $label)
            <label class="flex items-center gap-2 rounded-xl border border-stone-200 bg-white p-3 text-sm font-medium">
                <input type="hidden" name="selection_groups[{{ $index }}][{{ $field }}]" value="0">
                <input class="size-4 accent-orange-600" type="checkbox" name="selection_groups[{{ $index }}][{{ $field }}]" value="1" @checked($group[$field] ?? false)>
                {{ $label }}
            </label>
        @endforeach
    </div>
    <button class="btn-secondary mt-3" type="button" data-remove-row>Quitar grupo</button>
</div>
