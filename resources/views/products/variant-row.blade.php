<div class="repeater-row grid gap-3 rounded-2xl border border-stone-200 p-4 md:grid-cols-[1.4fr_1fr_.8fr_.5fr_auto]" data-row>
    @if(!empty($variant['ulid']))<input type="hidden" name="variants[{{ $index }}][ulid]" value="{{ $variant['ulid'] }}">@endif
    <div><label class="label">Tamaño o presentación</label><input class="input" name="variants[{{ $index }}][name]" value="{{ $variant['name'] ?? '' }}" placeholder="Ej. Familiar" required><p class="mt-1 text-xs text-stone-500">Para pizzas, usa el mismo nombre de tamaño en todos los sabores.</p></div>
    <div><label class="label">SKU</label><input class="input" name="variants[{{ $index }}][sku]" value="{{ $variant['sku'] ?? '' }}" placeholder="Opcional"></div>
    <div><label class="label">Precio (Bs)</label><input class="input" name="variants[{{ $index }}][price]" value="{{ $variant['price'] ?? '0.00' }}" inputmode="decimal" required></div>
    <div><label class="label">Orden</label><input class="input" name="variants[{{ $index }}][sort_order]" type="number" min="0" value="{{ $variant['sort_order'] ?? $index }}" required></div>
    <div class="flex flex-col justify-end gap-2 pb-2"><label class="text-xs"><input type="checkbox" name="variants[{{ $index }}][requires_preparation]" value="1" @checked($variant['requires_preparation'] ?? true)> Va a cocina</label><label class="text-xs"><input type="checkbox" name="variants[{{ $index }}][is_active]" value="1" @checked($variant['is_active'] ?? true)> Activa</label><button type="button" class="text-left text-sm font-medium text-red-600" data-remove-row>Quitar</button></div>
</div>
