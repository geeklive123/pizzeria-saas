@extends('layouts.app')

@section('title', $order->formattedNumber())
@section('heading', 'Venta')

@section('content')
@php($canRequestPayment = $order->items->isNotEmpty() && $order->items->every(fn ($item) => in_array($item->status, [\App\Enums\OrderItemStatus::Served, \App\Enums\OrderItemStatus::Cancelled], true)))
<div class="page-heading">
    <div>
        <a class="back-link" href="{{ route('orders.index') }}">← Pedidos abiertos</a>
        <h1>{{ $order->restaurantTable?->name ?? 'Para llevar' }} · {{ $order->formattedNumber() }}</h1>
        <p>Cuenta abierta desde {{ \App\Support\UiFormatter::date($order->opened_at, true) }}</p>
        @if ($order->type === \App\Enums\OrderType::Takeaway && ($order->customer_name || $order->customer_phone || $order->notes))
            <p class="mt-1 text-sm text-stone-600">
                {{ $order->customer_name ?: 'Cliente sin nombre' }}
                @if ($order->customer_phone) · {{ $order->customer_phone }} @endif
                @if ($order->notes) · {{ $order->notes }} @endif
            </p>
        @endif
    </div>
    <div class="flex flex-wrap gap-2">
        @can('update', $order)
            @if (in_array($order->status, [\App\Enums\OrderStatus::Open, \App\Enums\OrderStatus::ReadyForPayment], true) && $order->items->contains('status', \App\Enums\OrderItemStatus::Draft))
                <form method="POST" action="{{ route('orders.dispatch', $order->ulid) }}">
                    @csrf
                    <button class="btn-primary">Enviar nuevos a cocina</button>
                </form>
            @endif
            @if ($lastDispatch)
                @php($lastPrintAttempt = $lastDispatch->printAttempts->sortByDesc('attempted_at')->first())
                <form method="POST" action="{{ route('orders.kitchen.print', [$order->ulid, $lastDispatch->ulid]) }}">
                    @csrf
                    <button class="btn-secondary">{{ $lastPrintAttempt?->status === \App\Enums\PrintAttemptStatus::Failed ? 'Reintentar impresión' : 'Reimprimir última comanda' }}</button>
                    @if ($lastPrintAttempt)
                        <span class="ml-2 text-xs text-stone-500">Impresión: {{ $lastPrintAttempt->status->label() }}</span>
                    @endif
                </form>
            @endif
            @if ($order->status === \App\Enums\OrderStatus::Open && $canRequestPayment)
                <form method="POST" action="{{ route('orders.request-payment', $order->ulid) }}">
                    @csrf
                    <button class="btn-secondary">Solicitar cuenta</button>
                </form>
            @endif
        @endcan
        @can('create', \App\Models\Payment::class)
            <a class="btn-primary" href="{{ route('orders.checkout', $order->ulid) }}">Cobrar</a>
        @endcan
        @can('cancel', $order)
            @if ($order->status === \App\Enums\OrderStatus::Open)
                <form method="POST" action="{{ route('orders.cancel', $order->ulid) }}" onsubmit="return confirm('¿Cancelar la cuenta?')">
                    @csrf
                    <button class="btn-danger">Cancelar cuenta</button>
                </form>
            @endif
        @endcan
    </div>
</div>

<div class="grid gap-6 xl:grid-cols-[1.45fr_.85fr]">
    <section>
        @if ($order->status === \App\Enums\OrderStatus::Open)
            <div class="card mb-4 p-4">
                <input class="input" type="search" placeholder="Buscar producto..." data-pos-search autofocus>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="btn-secondary" type="button" data-pos-category="all">Todos</button>
                    @foreach ($products->pluck('category')->filter()->unique('id') as $category)
                        <button class="btn-secondary" type="button" data-pos-category="{{ $category->id }}">{{ $category->name }}</button>
                    @endforeach
                </div>
            </div>

            @if ($pizzaVariants->isNotEmpty())
                @php($initialPizzaSizeKey = $pizzaVariants->keys()->first())
                @php($initialPizzaVariants = $pizzaVariants->get($initialPizzaSizeKey, collect()))
                <div class="card mb-5 p-5" data-pizza-composer tabindex="-1" hidden>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                        <h2 class="text-lg font-semibold">Agregar pizza</h2>
                        <p class="text-sm text-stone-500">La venta normal inicia como pizza completa de un solo sabor. Combinar es opcional en Mediana y Familiar.</p>
                        @if ($pizzaVariants->flatten(1)->contains(fn ($variant) => $variant->sellable_availability->mode === 'recipe_pending'))
                            <p class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
                                Recetas pendientes de cantidades: estas pizzas pueden registrarse y enviarse a cocina, pero no reservarán ni descontarán ingredientes hasta configurar sus gramajes.
                            </p>
                        @endif
                        </div>
                        <button class="btn-secondary shrink-0" type="button" data-close-pizza-composer>Volver al catálogo</button>
                    </div>
                    <form method="POST" action="{{ route('orders.items.store', $order->ulid) }}" class="mt-4 space-y-4" data-pizza-form>
                        @csrf
                        <input type="hidden" name="quantity" value="1">
                        <div>
                            <label class="label">Tamaño</label>
                            <select class="input" data-pizza-size>
                                @foreach ($pizzaVariants as $sizeKey => $variants)
                                    <option value="{{ $sizeKey }}">{{ $variants->first()->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @foreach ($pizzaVariants as $sizeKey => $variants)
                            <template data-pizza-options="{{ $sizeKey }}">
                                <option value="">Selecciona un sabor</option>
                                @foreach ($variants as $variant)
                                    <option
                                        value="{{ $variant->ulid }}"
                                         data-size-key="{{ $sizeKey }}"
                                         data-product-id="{{ $variant->product_id }}"
                                        data-price="{{ $variant->price }}"
                                        data-availability="{{ $variant->sellable_availability->availableQuantity }}"
                                        data-availability-mode="{{ $variant->sellable_availability->mode }}"
                                    >{{ $variant->product->name }} · {{ \App\Support\UiFormatter::money($variant->price) }}</option>
                                @endforeach
                            </template>
                        @endforeach
                        <div>
                            <button class="btn-secondary" type="button" data-pizza-combine-toggle @if ($initialPizzaSizeKey === 'personal') hidden @endif>+ Combinar sabores</button>
                            <div class="mt-3" data-pizza-combination hidden>
                            <div class="flex items-center justify-between gap-3">
                            <span class="label">¿Cuántos sabores?</span>
                            <button class="text-sm font-semibold text-red-700" type="button" data-close-pizza-combination>Cancelar combinación</button>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ([2, 3, 4] as $count)
                                    <button class="btn-secondary" type="button" data-pizza-section-count="{{ $count }}">{{ $count }} {{ $count === 1 ? 'sabor' : 'sabores' }}</button>
                                @endforeach
                            </div>
                            <p class="mt-2 hidden rounded-xl bg-amber-50 p-3 text-sm text-amber-800" data-pizza-compatibility></p>
                            </div>
                        </div>
                        @foreach ([0, 1, 2, 3] as $index)
                            <div class="rounded-xl border border-stone-200 p-3" data-pizza-section="{{ $index }}" @if ($index > 0) hidden @endif>
                                <label class="label" data-pizza-section-label>Sabor</label>
                                <select class="input" name="sections[{{ $index }}][variant]" data-pizza-variant data-loaded-size-key="{{ $initialPizzaSizeKey }}">
                                    <option value="">Selecciona un sabor</option>
                                    @foreach ($initialPizzaVariants as $variant)
                                        <option
                                            value="{{ $variant->ulid }}"
                                             data-size-key="{{ $initialPizzaSizeKey }}"
                                             data-product-id="{{ $variant->product_id }}"
                                            data-price="{{ $variant->price }}"
                                            data-availability="{{ $variant->sellable_availability->availableQuantity }}"
                                            data-availability-mode="{{ $variant->sellable_availability->mode }}"
                                        >{{ $variant->product->name }} · {{ \App\Support\UiFormatter::money($variant->price) }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-stone-500" data-pizza-availability></p>
                            </div>
                        @endforeach
                        <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-pizza-error></p>
                        <div class="rounded-xl bg-orange-50 p-3 text-sm text-orange-900">
                            <p class="font-medium" data-pizza-price>Precio estimado: —</p>
                            <p class="mt-1 text-xs">Se usa el sabor de mayor precio. Los extras se suman y el servidor valida el total final.</p>
                        </div>

                        @if ($modifierOptions->isNotEmpty())
                            <div>
                                <span class="label">Extras e ingredientes removidos</span>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($modifierOptions as $index => $option)
                                        <div class="rounded-xl border border-stone-200 p-3">
                                            <label class="flex gap-2">
                                                <input type="checkbox" name="modifiers[{{ $index }}][option]" value="{{ $option->ulid }}">
                                                <span>
                                                    <strong class="text-sm">{{ $option->type === \App\Enums\ModifierOptionType::Add ? '+ ' : '- ' }}{{ $option->name }}</strong>
                                                    @if ($option->price_delta !== '0.00')
                                                        <small class="block text-orange-700">+ {{ \App\Support\UiFormatter::money($option->price_delta) }}</small>
                                                    @endif
                                                </span>
                                            </label>
                                            <select class="input mt-2" name="modifiers[{{ $index }}][section_position]">
                                                <option value="">Pizza completa</option>
                                                @foreach ([1, 2, 3, 4] as $position)
                                                    <option value="{{ $position }}">Solo sabor {{ $position }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label">Entrega</label>
                                <select class="input" name="fulfillment_type">
                                    <option value="dine_in" @selected($order->type === \App\Enums\OrderType::DineIn)>Comer aquí</option>
                                    <option value="takeaway" @selected($order->type === \App\Enums\OrderType::Takeaway)>Para llevar</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Observación</label>
                                <input class="input" name="notes" maxlength="500" placeholder="Bien cocida, cortar en 8…">
                            </div>
                        </div>
                        <button class="btn-primary w-full">Agregar pizza</button>
                    </form>
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($products as $product)
                    <article class="card p-5" data-pos-product data-name="{{ str($product->name)->lower() }}" data-category="{{ $product->category_id }}" data-product-kind="{{ $product->type->value }}">
                        <h2 class="text-lg font-semibold">{{ $product->name }}</h2>
                        <p class="text-xs text-stone-500">{{ $product->category?->name }}</p>
                        @if ($product->description)
                            <p class="mt-2 line-clamp-2 text-sm text-stone-600">{{ $product->description }}</p>
                        @endif
                        <div class="mt-4 space-y-3">
                            @foreach ($product->variants as $variant)
                                @if ($product->type === \App\Enums\ProductType::Pizza)
                                    <button
                                        class="btn-secondary flex w-full items-center justify-between gap-3 text-left"
                                        type="button"
                                        data-open-pizza-composer
                                         data-pizza-size-key="{{ $variant->compatibility_size_key }}"
                                         data-pizza-variant="{{ $variant->ulid }}"
                                         data-pizza-product-id="{{ $variant->product_id }}"
                                    >
                                        <span>{{ $variant->name }}</span>
                                        <strong class="text-orange-700">{{ \App\Support\UiFormatter::money($variant->price) }}</strong>
                                    </button>
                                    @continue
                                @endif
                                @php($available = (int) $variant->sellable_availability->availableQuantity)
                                <form method="POST" action="{{ route('orders.items.store', $order->ulid) }}" class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 p-3">
                                    @csrf
                                    <input type="hidden" name="variant" value="{{ $variant->ulid }}">
                                    <input type="hidden" name="quantity" value="1">
                                    <div>
                                        <p class="font-medium">{{ $variant->name }}</p>
                                        <p class="text-sm text-orange-700">{{ \App\Support\UiFormatter::money($variant->price) }}</p>
                                        <p class="text-xs {{ $available > 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $available > 0 ? 'Disponible: '.$available : 'AGOTADO' }}</p>
                                    </div>
                                    <button class="btn-primary size-12 px-0 text-xl" @disabled($available <= 0)>+</button>
                                </form>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="card p-8 text-center">
                <h2 class="text-xl font-semibold">Cuenta en proceso de cobro</h2>
                <p class="mt-2 text-stone-500">No se pueden agregar productos después de registrar pagos. El pedido seguirá activo hasta que cocina termine y todos los productos estén servidos.</p>
                <a class="btn-primary mt-5" href="{{ route('orders.checkout', $order->ulid) }}">Ver pagos</a>
            </div>
        @endif
    </section>

    <aside class="card self-start xl:sticky xl:top-24">
        <div class="card-header">
            <div>
                <h2 class="card-title">Pedido actual</h2>
                <p class="card-subtitle">Cada envío crea una tanda solo con borradores</p>
            </div>
        </div>
        <div class="divide-y">
            @forelse ($order->items as $item)
                <div class="p-4 {{ $item->status === \App\Enums\OrderItemStatus::Cancelled ? 'opacity-50' : '' }}">
                    <div class="flex justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $item->sections->isNotEmpty() ? 'Pizza '.$item->sections->first()->variant_name_snapshot : $item->productVariant->product->name.' · '.$item->productVariant->name }}</p>
                            @if ($item->sections->isNotEmpty())
                                <p class="text-sm text-stone-700">{{ $item->sections->pluck('product_name_snapshot')->join(' / ') }}</p>
                            @endif
                            <p class="text-xs text-stone-500">Cantidad: {{ \App\Support\UiFormatter::quantity($item->quantity) }}</p>
                            <p class="text-xs text-stone-500">{{ $item->fulfillment_type === \App\Enums\OrderType::Takeaway ? 'Para llevar' : 'Comer aquí' }} · {{ \App\Support\UiFormatter::orderItemStatus($item->status) }}</p>
                        </div>
                        <p class="font-semibold">{{ \App\Support\UiFormatter::money($item->line_total) }}</p>
                    </div>
                    @if ($item->sections->isNotEmpty())
                        <div class="mt-2 space-y-1">
                            @foreach ($item->sections as $section)
                                @foreach ($item->modifiers->where('order_item_section_id', $section->id) as $modifier)
                                    <p class="pl-4 text-xs font-semibold {{ $modifier->type === \App\Enums\ModifierOptionType::Add ? 'text-emerald-700' : 'text-red-700' }}">{{ $modifier->type === \App\Enums\ModifierOptionType::Add ? '+' : '-' }} {{ $modifier->name_snapshot }}</p>
                                @endforeach
                            @endforeach
                            @foreach ($item->modifiers->whereNull('order_item_section_id') as $modifier)
                                <p class="text-xs font-semibold {{ $modifier->type === \App\Enums\ModifierOptionType::Add ? 'text-emerald-700' : 'text-red-700' }}">{{ $modifier->type === \App\Enums\ModifierOptionType::Add ? '+' : '-' }} {{ $modifier->name_snapshot }}</p>
                            @endforeach
                        </div>
                    @endif
                    @if ($item->notes)
                        <p class="mt-2 text-xs font-semibold uppercase text-red-700">{{ $item->notes }}</p>
                    @endif

                    @if ($item->status === \App\Enums\OrderItemStatus::Draft && $order->status === \App\Enums\OrderStatus::Open)
                        @can('update', $order)
                            <details class="mt-3 rounded-xl border border-stone-200 p-3">
                                <summary class="cursor-pointer text-sm font-semibold text-orange-700">Editar</summary>
                            <form method="POST" action="{{ route('orders.items.update', [$order->ulid, $item->ulid]) }}" class="mt-3 grid grid-cols-[auto_1fr_auto] gap-2">
                                @csrf
                                @method('PUT')
                                @if ($item->sections->isNotEmpty())
                                    <div class="col-span-3 space-y-2">
                                        @foreach ($item->sections as $index => $section)
                                            <div>
                                                <label class="label">Sabor {{ $index + 1 }}</label>
                                                <select class="input" name="sections[{{ $index }}][variant]">
                                                    @foreach ($pizzaVariants->get($pizzaSizeKeys->get($section->product_variant_id), collect()) as $candidate)
                                                        <option value="{{ $candidate->ulid }}" @selected($candidate->id === $section->product_variant_id)>{{ $candidate->product->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                    @foreach ($modifierOptions as $modifierIndex => $option)
                                        @php($selected = $item->modifiers->firstWhere('modifier_option_id', $option->id))
                                        <div class="col-span-3 grid grid-cols-[1fr_8rem] gap-2">
                                            <label class="text-xs"><input type="checkbox" name="modifiers[{{ $modifierIndex }}][option]" value="{{ $option->ulid }}" @checked($selected)> {{ $option->name }}</label>
                                            <select class="input py-1 text-xs" name="modifiers[{{ $modifierIndex }}][section_position]">
                                                <option value="">Completa</option>
                                                @foreach ($item->sections as $section)
                                                    <option value="{{ $section->position }}" @selected($selected?->section?->position === $section->position)>Sabor {{ $section->position }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endforeach
                                @endif
                                <button class="btn-secondary px-4 text-lg" type="button" data-quantity-step="-1">−</button>
                                <input class="input text-center" name="quantity" value="{{ \App\Support\UiFormatter::inputQuantity($item->quantity) }}" inputmode="decimal" aria-label="Cantidad" data-quantity-input>
                                <button class="btn-secondary px-4 text-lg" type="button" data-quantity-step="1">+</button>
                                <select class="input col-span-3" name="fulfillment_type">
                                    <option value="dine_in" @selected($item->fulfillment_type === \App\Enums\OrderType::DineIn)>Comer aquí</option>
                                    <option value="takeaway" @selected($item->fulfillment_type === \App\Enums\OrderType::Takeaway)>Para llevar</option>
                                </select>
                                <input class="input col-span-3" name="notes" value="{{ $item->notes }}" placeholder="Observación">
                                <button class="btn-secondary col-span-3">Guardar cambios</button>
                            </form>
                            </details>
                            <form method="POST" action="{{ route('orders.items.cancel', [$order->ulid, $item->ulid]) }}" class="mt-2 text-right">
                                @csrf
                                <button class="text-xs font-semibold text-red-600">Quitar</button>
                            </form>
                        @endcan
                    @elseif ($item->status === \App\Enums\OrderItemStatus::Draft)
                        <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs font-medium text-amber-800">Pendiente de envío a cocina. Usa “Enviar nuevos a cocina” para continuar.</p>
                    @elseif ($item->status === \App\Enums\OrderItemStatus::Ready)
                        @can('update', $order)
                            <form method="POST" action="{{ route('orders.items.served', [$order->ulid, $item->ulid]) }}" class="mt-3">
                                @csrf
                                <button class="btn-primary w-full">Marcar servido</button>
                            </form>
                        @endcan
                    @elseif (! in_array($item->status, [\App\Enums\OrderItemStatus::Served, \App\Enums\OrderItemStatus::Cancelled], true))
                        @can('cancel', $order)
                            <form method="POST" action="{{ route('orders.items.cancel', [$order->ulid, $item->ulid]) }}" class="mt-3">
                                @csrf
                                <input class="input" name="reason" placeholder="Motivo de cancelación" required>
                                <button class="btn-danger mt-2 w-full">Cancelar con trazabilidad</button>
                            </form>
                        @endcan
                    @endif
                </div>
            @empty
                <div class="empty-state">Toca un producto para agregarlo.</div>
            @endforelse
        </div>
        <div class="border-t bg-stone-50 p-5">
            <div class="flex justify-between text-sm"><span>Subtotal</span><strong>{{ \App\Support\UiFormatter::money($order->subtotal) }}</strong></div>
            <div class="mt-3 flex justify-between text-xl"><span>Total</span><strong>{{ \App\Support\UiFormatter::money($order->total) }}</strong></div>
            <p class="mt-3 text-xs text-stone-500">Cobrar registra pagos. El pedido se finaliza y la mesa se libera únicamente cuando el saldo es cero y todos los productos están servidos.</p>
        </div>
    </aside>
</div>
@endsection
