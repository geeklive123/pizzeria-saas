@extends('layouts.app')

@section('title', $order->formattedNumber())
@section('heading', 'Venta')
@section('main-class', '!max-w-none')

@section('content')
@if($order->status === \App\Enums\OrderStatus::Paid)
    <div class='mb-4 flex flex-wrap justify-end gap-2'>
        @can('reprintKitchen', $order)
            <form method='POST' action='{{ route('orders.reprint.kitchen', $order->ulid) }}'>@csrf<button class='btn-secondary'>Reimprimir cocina</button></form>
        @endcan
        @can('reprintCustomerTicket', $order)
            <form method='POST' action='{{ route('orders.reprint.ticket', $order->ulid) }}'>@csrf<button class='btn-secondary'>Reimprimir ticket cliente</button></form>
        @endcan
    </div>
@endif
@php($hasDraft = $order->items->contains('status', \App\Enums\OrderItemStatus::Draft))
@php($isPerBatch = $order->charge_mode === \App\Enums\TableChargeMode::PerBatch)
@php($isAtEndCheckout = ! $isPerBatch && $order->type === \App\Enums\OrderType::DineIn && $order->status === \App\Enums\OrderStatus::ReadyForPayment)
@php($visibleItems = $isPerBatch ? $order->items->where('status', \App\Enums\OrderItemStatus::Draft) : $order->items)
@php([$accountStatus, $accountStatusClasses] = match ($order->status) {
    \App\Enums\OrderStatus::Open => ['CUENTA ABIERTA', 'bg-emerald-100 text-emerald-700'],
    \App\Enums\OrderStatus::ReadyForPayment => ['COBRO PENDIENTE', 'bg-amber-100 text-amber-700'],
    \App\Enums\OrderStatus::Paid => ['PAGADA', 'bg-emerald-100 text-emerald-700'],
    \App\Enums\OrderStatus::Cancelled => ['CANCELADA', 'bg-red-100 text-red-700'],
})
<section class="relative mb-6 overflow-hidden rounded-3xl border border-stone-200 bg-white shadow-sm shadow-stone-200/70">
    <span class="absolute inset-y-0 left-0 w-1 bg-orange-500" aria-hidden="true"></span>
    <div class="p-5 sm:p-6 lg:p-7">
        <a class="back-link inline-flex items-center gap-2" href="{{ route('orders.index') }}"><span aria-hidden="true">←</span> Pedidos abiertos</a>
        <div class="mt-5 flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        {{ str($order->restaurantTable?->name ?? 'Para llevar')->ucfirst() }}
                        <span class="text-orange-600">· {{ $order->formattedNumber() }}</span>
                    </h1>
                    <span class="rounded-full px-3 py-1 text-xs font-bold tracking-wide {{ $accountStatusClasses }}">{{ $accountStatus }}</span>
                </div>
                <p class="mt-3 flex items-center gap-2 text-sm text-slate-500">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>
                    Abierta desde {{ \App\Support\UiFormatter::date($order->opened_at, true) }}
                </p>
                @if($order->customer_name)<p class="mt-2 text-sm font-medium text-slate-600">Cliente: {{ $order->customer_name }}</p>@endif
            </div>
            <div class="flex flex-wrap gap-2 xl:justify-end">
                <button class="btn-secondary border-orange-200 text-orange-700 max-sm:w-full" type="button" data-order-history-open>
                    <svg class="mr-2 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>
                    HISTORIAL
                </button>
        @can('update', $order)
            @if ($order->status === \App\Enums\OrderStatus::Open && $hasDraft && ! $pendingDispatch && ! $isPerBatch)
                <form method='POST' action='{{ route('orders.dispatch', $order->ulid) }}'>@csrf
                    @can(\App\Enums\Permission::ApplyOrderDiscounts->value)
                        @if (($isPerBatch || $order->type === \App\Enums\OrderType::Takeaway) && $draftFinancial['eligible'])
                            <label class='mb-2 block'><span class='label'>Descuento en pizzas</span><span class='flex items-center gap-2'><input class='input w-32' name='discount_percentage' inputmode='decimal' placeholder='Ej. 10'><span>%</span></span></label>
                        @endif
                    @endcan
                    <button class='btn-primary'>{{ $order->type === \App\Enums\OrderType::Takeaway ? 'Cobrar y enviar' : ($isPerBatch ? 'Cobrar y confirmar tanda' : 'Confirmar pedido') }}</button>
                </form>
            @endif
            @if ($order->type === \App\Enums\OrderType::DineIn && ! $isPerBatch && $order->status === \App\Enums\OrderStatus::Open && $order->items->isNotEmpty() && ! $hasDraft)
                <form method='POST' action='{{ route('orders.request-payment', $order->ulid) }}'>@csrf
                    @can(\App\Enums\Permission::ApplyOrderDiscounts->value)
                        @if (\Brick\Math\BigDecimal::of($order->pizza_base_subtotal)->isGreaterThan('80.00'))
                            <label class='mb-2 block'><span class='label'>Descuento en pizzas</span><span class='flex items-center gap-2'><input class='input w-32' name='discount_percentage' inputmode='decimal' placeholder='Ej. 10'><span>%</span></span></label>
                        @endif
                    @endcan
                    <button class='btn-primary'>Finalizar mesa / Cobrar cuenta</button>
                </form>
            @endif
            @if ($order->type === \App\Enums\OrderType::DineIn && $isPerBatch && ! $hasDraft && ! $pendingDispatch && $orderBalance === '0.00' && $order->items->isNotEmpty())
                <form method='POST' action='{{ route('orders.finalize-table', $order->ulid) }}' onsubmit='return confirm(&quot;Todos los pedidos estan pagados. Finalizar y liberar la mesa?&quot;)'>@csrf<button class='btn-primary max-sm:w-full'>FINALIZAR MESA</button></form>
            @endif
        @endcan
        @can('create', \App\Models\Payment::class)
            @if($order->status === \App\Enums\OrderStatus::ReadyForPayment && ($isPerBatch || $order->type !== \App\Enums\OrderType::DineIn))<a class='btn-primary' href='{{ route('orders.checkout', $order->ulid) }}'>Continuar cobro</a>@endif
        @endcan
        @if(false)
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
        @endif
        @can('cancel', $order)
            @if ($order->status === \App\Enums\OrderStatus::Open)
                <form method="POST" action="{{ route('orders.cancel', $order->ulid) }}" onsubmit="return confirm('¿Cancelar la cuenta?')">
                    @csrf
                    <button class="btn-danger max-sm:w-full">CANCELAR CUENTA</button>
                </form>
            @endif
        @endcan
            </div>
        </div>
    </div>
</section>

<div class="grid min-w-0 items-start gap-6 min-[1180px]:grid-cols-[minmax(0,2.15fr)_minmax(20rem,1fr)]">
    <section class="min-w-0">
        @if ($order->status === \App\Enums\OrderStatus::Open)
            <div class="card mb-4 p-4">
                <input class="input" type="search" placeholder="Buscar producto..." data-pos-search autofocus>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="btn-secondary" type="button" data-pos-category="all">Todos</button>
                    @if ($promotions->isNotEmpty())
                        <button class="btn-secondary" type="button" data-pos-category="promotions">Promociones</button>
                    @endif
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
                        <fieldset>
                            <legend class="label">Tamaño</legend>
                            <input type="hidden" value="{{ $initialPizzaSizeKey }}" data-pizza-size>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                @foreach ($pizzaVariants as $sizeKey => $variants)
                                    <label class="cursor-pointer">
                                        <input
                                            class="peer sr-only"
                                            type="radio"
                                            name="pizza_size_choice"
                                            value="{{ $sizeKey }}"
                                            data-pizza-size-option
                                            @checked($loop->first)
                                        >
                                        <span class="flex min-h-16 flex-col justify-center rounded-xl border border-stone-200 bg-white px-3 py-2 text-left transition hover:border-orange-300 peer-checked:border-orange-500 peer-checked:bg-orange-50 peer-checked:text-orange-900 peer-checked:ring-2 peer-checked:ring-orange-200 peer-focus-visible:ring-2 peer-focus-visible:ring-orange-500">
                                            <strong class="text-sm">{{ $variants->first()->name }}</strong>
                                            <span class="text-xs text-stone-500">{{ $variants->count() }} {{ $variants->count() === 1 ? 'sabor' : 'sabores' }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
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

                        @if ($toppingOptions->isNotEmpty())
                            <fieldset>
                                <legend class="label">Toppings / extras</legend>
                                <p class="mb-2 text-xs text-stone-500">Se aplican una vez a la pizza completa, incluso cuando combinas sabores.</p>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($toppingOptions as $option)
                                        <label class="cursor-pointer">
                                            <input
                                                class="peer sr-only"
                                                type="checkbox"
                                                name="toppings[]"
                                                value="{{ $option->ulid }}"
                                                data-pizza-topping
                                                data-price-default="{{ $option->price_delta }}"
                                                data-size-prices='@json($option->sizeRules->whereNotNull('price_delta')->mapWithKeys(fn ($rule) => [$rule->size_key => $rule->price_delta]))'
                                            >
                                            <span class="flex min-h-14 items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 transition hover:border-orange-300 peer-checked:border-orange-500 peer-checked:bg-orange-50 peer-checked:text-orange-900 peer-checked:ring-2 peer-checked:ring-orange-200 peer-focus-visible:ring-2 peer-focus-visible:ring-orange-500">
                                                <strong class="text-sm">+ {{ $option->name }}</strong>
                                                <small class="font-semibold text-orange-700" data-topping-price-label></small>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif

                        <div class="rounded-xl bg-orange-50 p-3 text-sm text-orange-900">
                            <div class="flex justify-between"><span>Precio pizza</span><strong data-pizza-base-price>—</strong></div>
                            <div class="mt-1 flex justify-between"><span>Extras</span><strong data-pizza-extras-price>Bs 0,00</strong></div>
                            <div class="mt-2 flex justify-between border-t border-orange-200 pt-2 text-base"><span>TOTAL</span><strong data-pizza-price>—</strong></div>
                            <p class="mt-2 text-xs">Estimación inmediata. El servidor recalcula y valida el precio final.</p>
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

            <div class="grid gap-4 min-[1280px]:grid-cols-2 min-[1800px]:grid-cols-3">
                @foreach ($promotions as $promotion)
                    @php($promotionAvailable = \Brick\Math\BigDecimal::of($promotion->sellable_availability->availableQuantity)->isGreaterThan(0))
                    <article class="card border-orange-200 bg-orange-50/40 p-5" data-pos-product data-name="{{ str($promotion->productVariant->product->name)->lower() }}" data-category="promotions" data-product-kind="promotion">
                        <p class="text-xs font-semibold uppercase tracking-wide text-orange-700">Promoción</p>
                        <h2 class="mt-1 text-lg font-semibold">{{ $promotion->productVariant->product->name }}</h2>
                        <p class="mt-1 text-xl font-bold text-orange-700">{{ \App\Support\UiFormatter::money($promotion->productVariant->price) }}</p>
                        <div class="mt-3 space-y-1 text-xs text-stone-600">
                            @foreach ($promotion->components as $component)
                                <p>{{ $component->inventoryItem->name }} × {{ \App\Support\UiFormatter::quantity($component->quantity, $component->inventoryItem->unit->symbol) }}</p>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs {{ $promotionAvailable ? 'text-emerald-700' : 'text-red-700' }}">{{ $promotionAvailable ? 'Disponibles: '.\App\Support\UiFormatter::inputQuantity($promotion->sellable_availability->availableQuantity) : 'AGOTADO' }}</p>
                        <form method="POST" action="{{ route('orders.promotions.store', $order->ulid) }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="promotion" value="{{ $promotion->ulid }}">
                            <input type="hidden" name="quantity" value="1">
                            <button class="btn-primary w-full" @disabled(! $promotionAvailable)>Agregar promoción</button>
                        </form>
                    </article>
                @endforeach
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
        @elseif(! $isAtEndCheckout)
            <div class="card p-8 text-center">
                <h2 class="text-xl font-semibold">Cuenta en proceso de cobro</h2>
                <p class="mt-2 text-stone-500">No se pueden agregar productos después de registrar pagos. El pedido seguirá activo hasta que cocina termine y todos los productos estén servidos.</p>
                <a class="btn-primary mt-5" href="{{ route('orders.checkout', $order->ulid) }}">Ver pagos</a>
            </div>
        @endif
    </section>

    <aside class="card min-w-0 w-full self-start border-stone-200 shadow-md shadow-stone-200/40 min-[1180px]:sticky min-[1180px]:top-24 min-[1180px]:max-h-[calc(100vh-7rem)] min-[1180px]:overflow-y-auto min-[1180px]:overscroll-contain">
        <div class="flex items-start gap-3 border-b border-stone-100 px-5 py-5">
            <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-xl bg-orange-50 text-orange-600" aria-hidden="true">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h10v3h2v15l-2-1.5L15 21l-3-1.5L9 21l-2-1.5L5 21V6h2V3Z"/><path d="M9 3h6M8.5 10h7M8.5 14h7"/></svg>
            </span>
            <div>
                <h2 class="text-base font-bold tracking-tight text-slate-900">{{ $isAtEndCheckout ? 'COBRAR CUENTA' : 'PEDIDO ACTUAL' }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $isAtEndCheckout ? 'Completa el pago total para finalizar y liberar la mesa.' : 'Productos que agregarás en el próximo envío.' }}</p>
            </div>
        </div>
        @unless($isAtEndCheckout)
        <div class="space-y-3 p-4">
            @forelse ($visibleItems as $item)
                <article class="rounded-2xl border {{ $item->status === \App\Enums\OrderItemStatus::Draft ? 'border-orange-300 bg-orange-50/20' : 'border-stone-200 bg-white' }} p-4 shadow-sm {{ $item->status === \App\Enums\OrderItemStatus::Cancelled ? 'opacity-50' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-orange-600 text-xs font-bold text-white">{{ \App\Support\UiFormatter::inputQuantity($item->quantity) }}</span>
                            <div class="min-w-0">
                            <p class="truncate font-bold text-slate-900">{{ $item->displayName() }}</p>
                            @if ($item->sections->isNotEmpty())
                                <p class="mt-1 truncate text-sm font-medium uppercase text-slate-600">{{ $item->sections->pluck('product_name_snapshot')->join(' / ') }}</p>
                            @endif
                            @if (($item->configuration_snapshot['type'] ?? null) === 'promotion')
                                @foreach ($item->configuration_snapshot['components'] ?? [] as $component)
                                    <p class="mt-1 text-xs text-slate-500">{{ $component['inventory_item_name'] }} × {{ \App\Support\UiFormatter::quantity($component['quantity_applied'], $component['unit_symbol'] ?? null) }}</p>
                                @endforeach
                            @endif
                            @php($itemStatusLabel = match ($item->status) {
                                \App\Enums\OrderItemStatus::Draft => 'Sin enviar',
                                \App\Enums\OrderItemStatus::PendingPayment => 'Pendiente de pago',
                                \App\Enums\OrderItemStatus::Sent, \App\Enums\OrderItemStatus::Preparing => 'Enviado a cocina',
                                \App\Enums\OrderItemStatus::Ready => 'Listo en cocina',
                                \App\Enums\OrderItemStatus::Served => 'Servido',
                                \App\Enums\OrderItemStatus::Cancelled => 'Cancelado',
                            })
                            <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                @if($item->sections->isNotEmpty())<span>{{ $item->sections->count() }} {{ $item->sections->count() === 1 ? 'sabor' : 'sabores' }}</span><span>·</span>@endif
                                <span>{{ $item->fulfillment_type === \App\Enums\OrderType::Takeaway ? 'Para llevar' : 'Comer aquí' }}</span><span>·</span>
                                <span class="rounded-full px-2 py-0.5 font-semibold {{ $item->status === \App\Enums\OrderItemStatus::Draft ? 'bg-amber-100 text-amber-700' : ($item->status === \App\Enums\OrderItemStatus::Served ? 'bg-emerald-100 text-emerald-700' : ($item->status === \App\Enums\OrderItemStatus::Cancelled ? 'bg-red-100 text-red-700' : 'bg-blue-50 text-blue-700')) }}">{{ $itemStatusLabel }}</span>
                            </p>
                            </div>
                        </div>
                        <p class="shrink-0 font-bold text-slate-900">{{ \App\Support\UiFormatter::money($item->line_total) }}</p>
                    </div>
                    @if ($item->sections->isNotEmpty())
                        <div class="ml-10 mt-2 space-y-1">
                            @foreach ($item->sections as $section)
                                @foreach ($item->modifiers->where('order_item_section_id', $section->id) as $modifier)
                                    <p class="text-xs font-semibold {{ $modifier->type === \App\Enums\ModifierOptionType::Add ? 'text-emerald-700' : 'text-red-700' }}">{{ $modifier->type === \App\Enums\ModifierOptionType::Add ? '+' : '-' }} {{ $modifier->name_snapshot }}</p>
                                @endforeach
                            @endforeach
                            @foreach ($item->modifiers->whereNull('order_item_section_id') as $modifier)
                                <p class="text-xs font-semibold {{ $modifier->type === \App\Enums\ModifierOptionType::Add ? 'text-emerald-700' : 'text-red-700' }}">{{ $modifier->type === \App\Enums\ModifierOptionType::Add ? '+' : '-' }} {{ $modifier->name_snapshot }}</p>
                            @endforeach
                        </div>
                    @endif
                    @if ($item->notes)
                        <p class="ml-10 mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-800">{{ $item->notes }}</p>
                    @endif

                    @if ($item->status === \App\Enums\OrderItemStatus::Draft && $order->status === \App\Enums\OrderStatus::Open)
                        @can('update', $order)
                            <details class="group mt-3">
                                <summary class="ml-auto flex min-h-10 w-fit cursor-pointer list-none items-center gap-2 rounded-xl border border-stone-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-orange-300 hover:text-orange-700">
                                    <span aria-hidden="true">✎</span><span class="group-open:hidden">Editar</span><span class="hidden group-open:inline">Cerrar edición</span>
                                </summary>
                            <form method="POST" action="{{ route('orders.items.update', [$order->ulid, $item->ulid]) }}" class="mt-3 space-y-4 rounded-2xl border border-orange-100 bg-white p-4 shadow-sm">
                                @csrf
                                @method('PUT')
                                @if ($item->sections->isNotEmpty())
                                    <div class="space-y-3">
                                        @foreach ($item->sections as $index => $section)
                                            <div>
                                                <label class="label text-slate-700">Sabor {{ $index + 1 }}</label>
                                                <select class="input" name="sections[{{ $index }}][variant]">
                                                    @foreach ($pizzaVariants->get($pizzaSizeKeys->get($section->product_variant_id), collect()) as $candidate)
                                                        <option value="{{ $candidate->ulid }}" @selected($candidate->id === $section->product_variant_id)>{{ $candidate->product->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if($modifierOptions->isNotEmpty())
                                        <fieldset>
                                            <legend class="label text-slate-700">Modificadores</legend>
                                            <div class="space-y-2">
                                                @foreach ($modifierOptions as $modifierIndex => $option)
                                                    @php($selected = $item->modifiers->firstWhere('modifier_option_id', $option->id))
                                                    <div class="grid grid-cols-[1fr_8rem] items-center gap-2 rounded-xl border border-stone-200 p-2.5">
                                                        <label class="flex cursor-pointer items-center gap-2 text-xs font-medium"><input class="size-4 accent-orange-600" type="checkbox" name="modifiers[{{ $modifierIndex }}][option]" value="{{ $option->ulid }}" @checked($selected)> {{ $option->name }}</label>
                                                        <select class="input min-h-9 py-1 text-xs" name="modifiers[{{ $modifierIndex }}][section_position]">
                                                            <option value="">Completa</option>
                                                            @foreach ($item->sections as $section)
                                                                <option value="{{ $section->position }}" @selected($selected?->section?->position === $section->position)>Sabor {{ $section->position }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                    @if ($toppingOptions->isNotEmpty())
                                        <fieldset>
                                            <legend class="label text-slate-700">Toppings / extras</legend>
                                            <div class="space-y-2">
                                                @foreach ($toppingOptions as $option)
                                                    @php($selectedTopping = $item->modifiers->firstWhere('modifier_option_id', $option->id))
                                                    @php($itemSizeRule = $option->sizeRules->firstWhere('size_key', $item->configuration_snapshot['size_key'] ?? null))
                                                    <label class="flex min-h-12 cursor-pointer items-center justify-between gap-3 rounded-xl border border-stone-200 px-3 py-2 text-sm transition hover:border-orange-300">
                                                        <span class="flex items-center gap-2"><input class="size-4 accent-orange-600" type="checkbox" name="toppings[]" value="{{ $option->ulid }}" @checked($selectedTopping)><span>+ {{ $option->name }}</span></span>
                                                        <strong class="shrink-0 text-xs text-orange-600">{{ \App\Support\UiFormatter::money($itemSizeRule?->price_delta ?? $option->price_delta) }}</strong>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                @endif
                                <div>
                                    <label class="label text-slate-700">Cantidad</label>
                                    <div class="grid grid-cols-[3.5rem_1fr_3.5rem] gap-2">
                                        <button class="btn-secondary px-0 text-xl" type="button" data-quantity-step="-1" aria-label="Disminuir cantidad">−</button>
                                        <input class="input text-center font-semibold" name="quantity" value="{{ \App\Support\UiFormatter::inputQuantity($item->quantity) }}" inputmode="decimal" aria-label="Cantidad" data-quantity-input>
                                        <button class="btn-secondary px-0 text-xl" type="button" data-quantity-step="1" aria-label="Aumentar cantidad">+</button>
                                    </div>
                                </div>
                                <label class="block"><span class="label text-slate-700">Entrega</span><select class="input" name="fulfillment_type">
                                    <option value="dine_in" @selected($item->fulfillment_type === \App\Enums\OrderType::DineIn)>Comer aquí</option>
                                    <option value="takeaway" @selected($item->fulfillment_type === \App\Enums\OrderType::Takeaway)>Para llevar</option>
                                </select></label>
                                <label class="block"><span class="label text-slate-700">Observación</span><input class="input" name="notes" value="{{ $item->notes }}" placeholder="Ej: Sin cebolla, bien cocida..."></label>
                                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-orange-100 px-4 py-2.5 text-sm font-semibold text-orange-800 transition hover:bg-orange-200">Guardar cambios</button>
                            </form>
                            </details>
                            <form method="POST" action="{{ route('orders.items.cancel', [$order->ulid, $item->ulid]) }}" class="mt-2 text-right">
                                @csrf
                                <button class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-red-100 bg-red-50 px-3 text-sm font-semibold text-red-600 transition hover:bg-red-100"><span aria-hidden="true">⌫</span> Eliminar</button>
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
                </article>
            @empty
                @if($pendingDispatch)
                    <div class="rounded-2xl bg-amber-50 p-5 text-center text-sm font-medium text-amber-800">La tanda está bloqueada hasta completar su pago.</div>
                @else
                    <div class="grid min-h-52 place-items-center rounded-2xl border border-dashed border-stone-300 bg-stone-50/40 p-6 text-center">
                        <div>
                            <svg class="mx-auto size-16 text-stone-400" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 43h36M18 39h28M21 39a11 11 0 0 1 22 0M32 28v-5M28 21h8"/><path d="M12 16h5M14.5 13.5v5M48 12h6M51 9v6M51 25h5M53.5 22.5v5"/></svg>
                            <p class="mt-3 font-semibold text-slate-800">Aún no agregaste productos</p>
                            <p class="mx-auto mt-1 max-w-64 text-sm text-slate-500">Selecciona un producto del catálogo para comenzar.</p>
                        </div>
                    </div>
                @endif
            @endforelse
        </div>
        @can('update', $order)
            <div class="border-t border-stone-100 px-4 py-3">
                <form method="POST" action="{{ route('orders.customer.update', $order->ulid) }}" class="rounded-2xl border border-stone-200 bg-white p-3 shadow-sm">
                    @csrf
                    @method('PUT')
                    <label class="label text-slate-700">Cliente <span class="font-normal text-slate-500">(opcional)</span></label>
                    <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                        <input class="input" name="customer_name" value="{{ $order->customer_name }}" maxlength="255" placeholder="Nombre del cliente">
                        <button class="btn-secondary px-5 text-orange-600">Guardar</button>
                    </div>
                </form>
            </div>
        @endcan
        @endunless
        @include('orders._per_batch_payment')
        @include('orders._at_end_payment')
        @if(! ($isPerBatch && $pendingDispatch) && ! (! $isPerBatch && $order->type === \App\Enums\OrderType::DineIn && $order->status === \App\Enums\OrderStatus::ReadyForPayment))
        <div class="border-t border-stone-100 bg-white p-5">
            @if($isPerBatch)
                <form method="POST" action="{{ route('orders.dispatch', $order->ulid) }}" class="space-y-3">
                    @csrf
                    <p class="flex justify-between gap-4 text-sm text-slate-700"><span>Productos</span><strong class="text-slate-900">{{ \App\Support\UiFormatter::money($draftFinancial['pizza_base']) }}</strong></p>
                    <p class="flex justify-between gap-4 text-sm text-slate-700"><span>Extras</span><strong class="text-slate-900">{{ \App\Support\UiFormatter::money($draftFinancial['extras']) }}</strong></p>
                    <p class="flex justify-between gap-4 text-sm text-slate-700"><span>Otros</span><strong class="text-slate-900">{{ \App\Support\UiFormatter::money($draftFinancial['other']) }}</strong></p>
                    <p class="flex justify-between gap-4 text-sm text-red-600"><span>Descuento</span><strong>-{{ \App\Support\UiFormatter::money($draftFinancial['discount']) }}</strong></p>
                    @can(\App\Enums\Permission::ApplyOrderDiscounts->value)
                        @if($draftFinancial['eligible'])
                            <label class="block pt-2"><span class="label">Descuento pizzas</span><span class="flex items-center gap-2"><input class="input" name="discount_percentage" inputmode="decimal" placeholder="Ej. 10"><span>%</span></span></label>
                        @endif
                    @endcan
                    <p class="flex items-end justify-between gap-4 border-t border-stone-300 pt-4 text-xl font-bold text-slate-900"><span>TOTAL</span><strong class="text-2xl">{{ \App\Support\UiFormatter::money($draftFinancial['total']) }}</strong></p>
                    <p class="rounded-xl border border-orange-100 bg-orange-50/70 p-3 text-xs leading-5 text-slate-600"><span class="mr-1 font-bold text-orange-600">ⓘ</span> Cobrar registra pagos. El pedido se finaliza y la mesa se libera únicamente cuando el saldo es cero y todos los productos están servidos.</p>
                    @if($hasDraft)<button class="btn-primary mt-2 min-h-14 w-full bg-gradient-to-r from-orange-600 to-orange-500 text-base shadow-md shadow-orange-200 hover:from-orange-700 hover:to-orange-600">COBRAR Y CONFIRMAR TANDA</button>@endif
                </form>
            @else
                <div class="space-y-3">
                    <p class="flex justify-between gap-4 text-sm text-slate-700"><span>Productos</span><strong class="text-slate-900">{{ \App\Support\UiFormatter::money($order->pizza_base_subtotal) }}</strong></p>
                    <p class="flex justify-between gap-4 text-sm text-slate-700"><span>Extras</span><strong class="text-slate-900">{{ \App\Support\UiFormatter::money($order->extras_subtotal) }}</strong></p>
                    <p class="flex justify-between gap-4 text-sm text-slate-700"><span>Otros</span><strong class="text-slate-900">{{ \App\Support\UiFormatter::money($order->other_subtotal) }}</strong></p>
                    <p class="flex justify-between gap-4 text-sm text-red-600"><span>Descuento</span><strong>-{{ \App\Support\UiFormatter::money($order->discount_total) }}</strong></p>
                    <p class="flex items-end justify-between gap-4 border-t border-stone-300 pt-4 text-xl font-bold text-slate-900"><span>TOTAL</span><strong class="text-2xl">{{ \App\Support\UiFormatter::money($order->total) }}</strong></p>
                </div>
                <p class="mt-3 rounded-xl border border-orange-100 bg-orange-50/70 p-3 text-xs leading-5 text-slate-600"><span class="mr-1 font-bold text-orange-600">ⓘ</span> Cobrar registra pagos. El pedido se finaliza y la mesa se libera únicamente cuando el saldo es cero y todos los productos están servidos.</p>
            @endif
        </div>
        @endif
    </aside>
</div>
@include('orders._history_modal', ['history' => $orderHistory])
@endsection
