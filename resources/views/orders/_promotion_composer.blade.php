@php($pizzaIndex = 0)
<div class="mt-4 rounded-2xl border border-orange-200 bg-white p-4" data-promotion-composer hidden>
    <div class="flex items-start justify-between gap-3">
        <div><h3 class="font-bold">{{ $promotion->productVariant->product->name }}</h3><p class="text-xs text-stone-500">Configura cada pizza incluida. El servidor validará categoría, tamaño, reglas, receta y stock.</p></div>
        <button class="text-sm font-semibold text-red-700" type="button" data-close-promotion-composer>Cerrar</button>
    </div>
    <form method="POST" action="{{ route('orders.promotions.store', $order->ulid) }}" class="mt-4 space-y-4">
        @csrf
        <input type="hidden" name="promotion" value="{{ $promotion->ulid }}">
        <input type="hidden" name="quantity" value="1">
        @foreach($promotion->selectionGroups as $group)
            @php($eligibleVariants = $pizzaVariants->get($group->size_key, collect())->filter(fn ($variant) => (int) $variant->product->category_id === (int) $group->category_id))
            @for($slot = 0; $slot < $group->quantity; $slot++)
                <fieldset class="rounded-xl border border-stone-200 p-3" data-promotion-pizza>
                    <input type="hidden" name="pizzas[{{ $pizzaIndex }}][group]" value="{{ $group->ulid }}">
                    <legend class="px-1 font-semibold">Pizza {{ $slot + 1 }} · {{ $group->size_key === 'familiar' ? 'Grande (Familiar)' : ucfirst($group->size_key) }}</legend>
                    @if($group->allow_fusions)
                        <label class="label mt-2">Cantidad de sabores</label>
                        <select class="input" data-promotion-section-count>
                            @foreach([1, 2, 3, 4] as $count)<option value="{{ $count }}">{{ $count }} sabor(es)</option>@endforeach
                        </select>
                    @endif
                    <div class="mt-3 space-y-2">
                        @foreach([0, 1, 2, 3] as $section)
                            <div data-promotion-section @if($section > 0) hidden @endif>
                                <label class="label">Sabor {{ $section + 1 }}</label>
                                <select class="input" name="pizzas[{{ $pizzaIndex }}][sections][{{ $section }}][variant]" @if($section > 0) disabled @endif required>
                                    <option value="">Seleccionar…</option>
                                    @foreach($eligibleVariants as $variant)<option value="{{ $variant->ulid }}">{{ $variant->product->name }}</option>@endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                    @if($group->allow_extras && $toppingOptions->isNotEmpty())
                        <div class="mt-3"><span class="label">Toppings / extras</span><div class="grid gap-2 sm:grid-cols-2">@foreach($toppingOptions as $option)<label class="rounded-lg border border-stone-200 p-2 text-sm"><input type="checkbox" name="pizzas[{{ $pizzaIndex }}][toppings][]" value="{{ $option->ulid }}"> + {{ $option->name }}</label>@endforeach</div></div>
                    @endif
                    @php($allowedModifiers = $modifierOptions->filter(fn ($option) => ($group->allow_extras && $option->type === \App\Enums\ModifierOptionType::Add) || ($group->allow_removals && $option->type === \App\Enums\ModifierOptionType::Remove)))
                    @if($allowedModifiers->isNotEmpty())
                        <div class="mt-3"><span class="label">Extras y removidos</span><div class="grid gap-2 sm:grid-cols-2">@foreach($allowedModifiers->values() as $modifierIndex => $option)<div class="rounded-lg border border-stone-200 p-2 text-sm"><label><input type="checkbox" name="pizzas[{{ $pizzaIndex }}][modifiers][{{ $modifierIndex }}][option]" value="{{ $option->ulid }}"> {{ $option->type === \App\Enums\ModifierOptionType::Add ? '+' : '-' }} {{ $option->name }}</label><select class="input mt-1" name="pizzas[{{ $pizzaIndex }}][modifiers][{{ $modifierIndex }}][section_position]"><option value="">Pizza completa</option>@foreach([1,2,3,4] as $position)<option value="{{ $position }}">Solo sabor {{ $position }}</option>@endforeach</select></div>@endforeach</div></div>
                    @endif
                </fieldset>
                @php($pizzaIndex++)
            @endfor
        @endforeach
        @if($promotion->components->isNotEmpty())<div class="rounded-xl bg-stone-50 p-3"><p class="text-xs font-bold uppercase text-stone-600">Incluye</p>@foreach($promotion->components as $component)<p class="text-sm">{{ $component->inventoryItem->name }} × {{ \App\Support\UiFormatter::quantity($component->quantity, $component->inventoryItem->unit->symbol) }}</p>@endforeach</div>@endif
        <label class="block"><span class="label">Entrega</span><select class="input" name="fulfillment_type"><option value="dine_in" @selected($order->type === \App\Enums\OrderType::DineIn)>Comer aquí</option><option value="takeaway" @selected($order->type === \App\Enums\OrderType::Takeaway)>Para llevar</option></select></label>
        <label class="block"><span class="label">Observación</span><input class="input" name="notes" maxlength="500"></label>
        <div class="rounded-xl bg-orange-50 p-3 text-sm"><div class="flex justify-between"><span>Precio base</span><strong>{{ \App\Support\UiFormatter::money($promotion->productVariant->price) }}</strong></div><p class="mt-1 text-xs">Los extras permitidos se recalculan y suman en el servidor. Las pizzas incluidas no suman su precio normal.</p></div>
        <button class="btn-primary w-full">AGREGAR PROMOCIÓN</button>
    </form>
</div>
