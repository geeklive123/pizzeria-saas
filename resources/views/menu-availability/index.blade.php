@extends('layouts.app')
@section('title', 'Menú y disponibilidad')
@section('heading', 'Menú y disponibilidad')
@section('content')
<div class="mx-auto min-w-0 max-w-[1500px]" data-menu-browser>
    <div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950">Menú y disponibilidad</h1>
            <p class="mt-1 text-sm text-slate-600">Consulta qué productos están disponibles y qué falta para prepararlos.</p>
        </div>
        <label class="relative block w-full xl:max-w-sm" for="menu-search">
            <span class="sr-only">Buscar producto</span>
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-xl text-slate-500" aria-hidden="true">⌕</span>
            <input class="input pl-11" id="menu-search" type="search" placeholder="Buscar producto..." autocomplete="off" data-menu-search>
        </label>
    </div>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de disponibilidad">
        @foreach ([
            ['available', '✓', 'Disponibles', $catalog->summary['available'], 'bg-emerald-100 text-emerald-700'],
            ['limited', '!', 'Stock limitado', $catalog->summary['limited'], 'bg-amber-100 text-amber-700'],
            ['unavailable', '×', 'Sin stock', $catalog->summary['unavailable'], 'bg-red-100 text-red-700'],
            ['total', '▦', 'Total productos', $catalog->summary['total'], 'bg-slate-100 text-slate-700'],
        ] as [$key, $icon, $label, $count, $tone])
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <span class="grid size-12 shrink-0 place-items-center rounded-full text-xl font-bold {{ $tone }}" aria-hidden="true">{{ $icon }}</span>
                <div><p class="text-2xl font-bold text-slate-950 tabular-nums" data-menu-summary="{{ $key }}">{{ $count }}</p><p class="text-sm text-slate-600">{{ $label }}</p></div>
            </div>
        @endforeach
    </section>

    <div class="mt-5 flex gap-2 overflow-x-auto pb-2" aria-label="Filtrar por categoría">
        <button class="min-h-11 shrink-0 rounded-xl border border-orange-500 bg-orange-600 px-4 text-sm font-semibold text-white" type="button" data-menu-category="all" aria-pressed="true"><span class="mr-2" aria-hidden="true">▦</span>Todas</button>
        @foreach ($catalog->categories as $category)
            <button class="min-h-11 shrink-0 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button" data-menu-category="{{ $category['key'] }}" aria-pressed="false">{{ $category['name'] }}</button>
        @endforeach
    </div>

    <section class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" aria-label="Productos del menú" data-menu-grid>
        @forelse ($catalog->products as $product)
            @php
                $icon = match ($product->type) {
                    \App\Enums\ProductType::Pizza => '◒',
                    \App\Enums\ProductType::Beverage => '▯',
                    \App\Enums\ProductType::Extra => '＋',
                    \App\Enums\ProductType::Combo => '▦',
                    \App\Enums\ProductType::Other => '◇',
                };
            @endphp
            <article class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" data-menu-product data-menu-category-key="{{ $product->categoryKey ?? 'uncategorized' }}" data-menu-status="{{ $product->status->value }}" data-menu-searchable="{{ str($product->name.' '.($product->categoryName ?? ''))->lower() }}">
                <div class="relative grid h-32 place-items-center overflow-hidden bg-gradient-to-br from-orange-50 via-amber-50 to-stone-100">
                    <span class="text-6xl text-orange-500/80" aria-hidden="true">{{ $icon }}</span>
                    <span class="absolute right-3 top-3 rounded-full px-3 py-1.5 text-xs font-bold shadow-sm {{ $product->status->badgeClasses() }}">{{ $product->status->label() }}</span>
                    @if ($product->categoryName)<span class="absolute bottom-3 left-3 rounded-lg bg-white/90 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $product->categoryName }}</span>@endif
                </div>
                <div class="flex flex-1 flex-col p-4">
                    <h2 class="text-lg font-bold text-slate-950">{{ $product->name }}</h2>
                    <div class="mt-4 grid gap-2 {{ count($product->variants) > 1 ? 'grid-cols-2' : 'grid-cols-1' }}">
                        @foreach ($product->variants as $variant)
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-2.5">
                                <p class="truncate text-xs font-semibold text-slate-700">{{ $variant->name }}</p>
                                <p class="mt-1 font-bold text-slate-950">{{ \App\Support\UiFormatter::money($variant->price) }}</p>
                                <p class="mt-1 text-xs font-semibold {{ $variant->status === \App\Enums\MenuAvailabilityStatus::Available ? 'text-emerald-700' : ($variant->status === \App\Enums\MenuAvailabilityStatus::Limited ? 'text-amber-700' : ($variant->status === \App\Enums\MenuAvailabilityStatus::Unavailable ? 'text-red-700' : 'text-slate-600')) }}">@if ($variant->availableQuantity === null)Disponibilidad no estimada · {{ $variant->status->label() }}@else{{ $variant->availableQuantity }} {{ $variant->availableQuantity === '1' ? 'disponible' : 'disponibles' }} · {{ $variant->status->label() }}@endif</p>
                            </div>
                        @endforeach
                    </div>
                    <button class="btn-secondary mt-4 w-full border-orange-200 text-orange-700 hover:bg-orange-50" type="button" data-menu-open="{{ $product->ulid }}"><span class="mr-2" aria-hidden="true">ⓘ</span>Ver disponibilidad</button>
                </div>
            </article>
        @empty
            <div class="card p-8 text-center text-sm text-slate-500 sm:col-span-2 lg:col-span-3 2xl:col-span-4">No hay productos activos disponibles para consulta.</div>
        @endforelse
    </section>
    <div class="mt-6 hidden rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500" data-menu-empty>No hay productos que coincidan con la búsqueda y categoría.</div>

    @foreach ($catalog->products as $product)
        @php
            $icon = match ($product->type) {
                \App\Enums\ProductType::Pizza => '◒',
                \App\Enums\ProductType::Beverage => '▯',
                \App\Enums\ProductType::Extra => '＋',
                \App\Enums\ProductType::Combo => '▦',
                \App\Enums\ProductType::Other => '◇',
            };
        @endphp
        <template data-menu-template="{{ $product->ulid }}">
            <div class="flex h-full flex-col">
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                    <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $product->categoryName ?? \App\Support\UiFormatter::productType($product->type) }}</p><h2 class="mt-1 text-2xl font-bold text-slate-950">{{ $product->name }}</h2><span class="mt-2 inline-flex rounded-full px-3 py-1.5 text-xs font-bold {{ $product->status->badgeClasses() }}">{{ $product->status->label() }}</span></div>
                    <button class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-xl text-slate-600 hover:bg-slate-50" type="button" data-menu-close aria-label="Cerrar detalle">×</button>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    <div class="grid h-44 place-items-center rounded-2xl bg-gradient-to-br from-orange-50 via-amber-50 to-stone-100"><span class="text-7xl text-orange-500/80" aria-hidden="true">{{ $icon }}</span></div>
                    <h3 class="mt-5 font-bold text-slate-950">Presentaciones</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($product->variants as $variant)
                            <section class="rounded-xl border border-slate-200 p-3">
                                <div class="flex items-start justify-between gap-3"><p class="font-semibold text-slate-900">{{ $variant->name }}</p><p class="font-bold text-slate-950">{{ \App\Support\UiFormatter::money($variant->price) }}</p></div>
                                <p class="mt-2 text-sm font-semibold {{ $variant->status === \App\Enums\MenuAvailabilityStatus::Available ? 'text-emerald-700' : ($variant->status === \App\Enums\MenuAvailabilityStatus::Limited ? 'text-amber-700' : ($variant->status === \App\Enums\MenuAvailabilityStatus::Unavailable ? 'text-red-700' : 'text-slate-600')) }}">@if ($variant->availableQuantity === null)Disponibilidad no estimada · {{ $variant->status->label() }}@else{{ $variant->availableQuantity }} {{ $variant->availableQuantity === '1' ? 'disponible' : 'disponibles' }} · {{ $variant->status->label() }}@endif</p>
                                @if ($variant->limitingComponents)
                                    <div class="mt-3 space-y-1 border-t border-slate-100 pt-3">@foreach ($variant->limitingComponents as $component)<p class="text-sm text-slate-700"><span class="font-semibold">Faltante:</span> {{ $component }}</p>@endforeach</div>
                                @endif
                            </section>
                        @endforeach
                    </div>
                    @if (in_array($product->status, [\App\Enums\MenuAvailabilityStatus::Limited, \App\Enums\MenuAvailabilityStatus::Unavailable], true))
                        <div class="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900"><p class="font-bold">Disponibilidad limitada por stock interno.</p><p class="mt-1">Consulta los componentes señalados para informar a cocina.</p></div>
                    @endif
                </div>
                <div class="border-t border-slate-200 p-5"><button class="btn-secondary w-full" type="button" data-menu-close>Cerrar</button></div>
            </div>
        </template>
    @endforeach
</div>

<div class="fixed inset-0 z-50 hidden" data-menu-drawer>
    <div class="absolute inset-0 bg-slate-950/40" data-menu-close></div>
    <aside class="absolute inset-y-0 right-0 w-full max-w-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Detalle de disponibilidad" data-menu-drawer-content></aside>
</div>

<script>
    (() => {
        const browser = document.querySelector('[data-menu-browser]');
        const drawer = document.querySelector('[data-menu-drawer]');
        if (! browser || ! drawer) return;
        const search = browser.querySelector('[data-menu-search]');
        const filters = [...browser.querySelectorAll('[data-menu-category]')];
        const products = [...browser.querySelectorAll('[data-menu-product]')];
        const empty = browser.querySelector('[data-menu-empty]');
        const drawerContent = drawer.querySelector('[data-menu-drawer-content]');
        let category = 'all';
        let opener = null;

        const refresh = () => {
            const term = search.value.trim().toLocaleLowerCase('es');
            const counts = { available: 0, limited: 0, unavailable: 0, total: 0 };
            products.forEach((product) => {
                product.hidden = ! ((category === 'all' || product.dataset.menuCategoryKey === category) && (! term || product.dataset.menuSearchable.includes(term)));
                if (! product.hidden) {
                    if (Object.hasOwn(counts, product.dataset.menuStatus)) counts[product.dataset.menuStatus]++;
                    counts.total++;
                }
            });
            Object.entries(counts).forEach(([key, count]) => {
                const output = browser.querySelector('[data-menu-summary="' + key + '"]');
                if (output) output.textContent = count;
            });
            empty.classList.toggle('hidden', counts.total > 0 || products.length === 0);
        };

        filters.forEach((filter) => filter.addEventListener('click', () => {
            category = filter.dataset.menuCategory;
            filters.forEach((button) => {
                const active = button === filter;
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.classList.toggle('border-orange-500', active);
                button.classList.toggle('bg-orange-600', active);
                button.classList.toggle('text-white', active);
                button.classList.toggle('border-slate-200', ! active);
                button.classList.toggle('bg-white', ! active);
                button.classList.toggle('text-slate-700', ! active);
            });
            refresh();
        }));
        search.addEventListener('input', refresh);

        browser.querySelectorAll('[data-menu-open]').forEach((button) => button.addEventListener('click', () => {
            const template = browser.querySelector('[data-menu-template="' + button.dataset.menuOpen + '"]');
            if (! template) return;
            opener = button;
            drawerContent.replaceChildren(template.content.cloneNode(true));
            drawer.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            drawerContent.querySelector('[data-menu-close]')?.focus();
        }));
        drawer.addEventListener('click', (event) => {
            if (! event.target.closest('[data-menu-close]')) return;
            drawer.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            drawerContent.replaceChildren();
            opener?.focus();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && ! drawer.classList.contains('hidden')) drawer.querySelector('[data-menu-close]')?.click();
        });
    })();
</script>
@endsection
