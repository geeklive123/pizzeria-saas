@php($stockControlLocked = (bool) ($variant['stock_control_locked'] ?? false))
<div class="repeater-row grid gap-3 rounded-2xl border border-stone-200 p-4 md:grid-cols-2 xl:grid-cols-[1.4fr_1fr_.8fr_.5fr_1.2fr_auto]" data-row>
    @if(!empty($variant['ulid']))<input type="hidden" name="variants[{{ $index }}][ulid]" value="{{ $variant['ulid'] }}">@endif
    <div><label class="label">Tamaño o presentación</label><input class="input" name="variants[{{ $index }}][name]" value="{{ $variant['name'] ?? '' }}" placeholder="Ej. Familiar" required><p class="mt-1 text-xs text-stone-500">Para pizzas, usa el mismo nombre de tamaño en todos los sabores.</p></div>
    <div><label class="label">SKU</label><input class="input" name="variants[{{ $index }}][sku]" value="{{ $variant['sku'] ?? '' }}" placeholder="Opcional"></div>
    <div><label class="label">Precio (Bs)</label><input class="input" name="variants[{{ $index }}][price]" value="{{ $variant['price'] ?? '0.00' }}" inputmode="decimal" required></div>
    <div><label class="label">Orden</label><input class="input" name="variants[{{ $index }}][sort_order]" type="number" min="0" value="{{ $variant['sort_order'] ?? $index }}" required></div>
    <div>
        <label class="label">Inventario</label>
        @if($stockControlLocked)<input type="hidden" name="variants[{{ $index }}][track_stock]" value="1">@endif
        <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="variants[{{ $index }}][track_stock]" value="1" @checked($variant['track_stock'] ?? false) @disabled($stockControlLocked)> Controlar stock</label>
        @if($stockControlLocked)<input type="hidden" name="variants[{{ $index }}][inventory_unit_id]" value="{{ $variant['inventory_unit_id'] }}">@endif
        <select class="input mt-2 min-h-9 py-1 text-xs" name="variants[{{ $index }}][inventory_unit_id]" @disabled($stockControlLocked)>
            <option value="">Selecciona unidad</option>
            @foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string)($variant['inventory_unit_id'] ?? '') === (string)$unit->id)>{{ $unit->name }} ({{ $unit->symbol }})</option>@endforeach
        </select>
        <p class="mt-1 text-xs text-stone-500">{{ $stockControlLocked ? 'Conserva el historial de inventario existente.' : 'Solo para venta directa. Sin marcar: sin reservas ni descuento.' }}</p>
    </div>
    <div class="flex flex-col justify-end gap-2 pb-2"><label class="text-xs"><input type="checkbox" name="variants[{{ $index }}][requires_preparation]" value="1" @checked($variant['requires_preparation'] ?? true)> Va a cocina</label><label class="text-xs"><input type="checkbox" name="variants[{{ $index }}][is_active]" value="1" @checked($variant['is_active'] ?? true)> Activa</label><button type="button" class="text-left text-sm font-medium text-red-600" data-remove-row>Quitar</button></div>
</div>
