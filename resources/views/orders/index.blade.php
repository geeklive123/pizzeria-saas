@extends('layouts.app')
@section('title', 'Pedidos')
@section('heading', 'Pedidos')
@section('content')
<div class="mx-auto min-w-0 max-w-[1500px]" data-orders-browser>
    <div class="mb-5">
        <h1 class="text-3xl font-bold tracking-tight text-slate-950">Pedidos</h1>
        <p class="mt-1 text-sm text-slate-600">Gestiona los pedidos abiertos y consulta el historial de pedidos pagados.</p>
    </div>

    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4" aria-label="Herramientas de pedidos">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
            <label class="relative min-w-0 flex-1" for="order-search">
                <span class="sr-only">Buscar pedidos</span>
                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-xl text-slate-500" aria-hidden="true">⌕</span>
                <input id="order-search" class="input pl-11" type="search" placeholder="Buscar por número de pedido, mesa o usuario..." autocomplete="off" data-order-search>
            </label>
            <div class="flex gap-2 overflow-x-auto pb-1 xl:pb-0" aria-label="Filtrar por tipo de atención">
                @foreach ([['all', '▦', 'Todos'], ['dine_in', '▤', 'Mesas'], ['takeaway', '▢', 'Para llevar']] as [$value, $icon, $label])
                    <button class="min-h-11 shrink-0 rounded-xl border px-4 text-sm font-semibold transition {{ $loop->first ? 'border-orange-500 bg-orange-50 text-orange-600' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}" type="button" data-order-filter="{{ $value }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}"><span class="mr-2" aria-hidden="true">{{ $icon }}</span>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex gap-2 xl:ml-auto">
                <a class="btn-secondary flex-1 whitespace-nowrap xl:flex-none" href="{{ route('tables.index') }}"><span class="mr-2" aria-hidden="true">▤</span>Ver mesas</a>
                @can('create', \App\Models\Order::class)
                    <a class="btn-primary flex-1 whitespace-nowrap xl:flex-none" href="{{ route('orders.takeaway.create') }}"><span class="mr-2 text-lg" aria-hidden="true">＋</span>Para llevar</a>
                @endcan
            </div>
        </div>
        <p class="mt-3 hidden text-sm text-slate-500" data-order-empty-filter>No hay pedidos que coincidan con la búsqueda y el filtro.</p>
    </section>

    <section class="overflow-hidden rounded-2xl border border-orange-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-4 border-b border-orange-100 bg-orange-50/60 px-4 py-4 sm:px-5">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-orange-600 text-xl font-bold text-white" aria-hidden="true">◷</span>
                <div class="min-w-0"><h2 class="text-lg font-bold text-slate-950">Pedidos abiertos</h2><p class="text-sm text-slate-600">Cuentas en atención o listas para cobrar.</p></div>
            </div>
            <span class="shrink-0 rounded-full bg-orange-100 px-3 py-1.5 text-xs font-bold text-orange-600">{{ $orders->count() }} {{ $orders->count() === 1 ? 'pedido' : 'pedidos' }}</span>
        </div>
        <div class="hidden grid-cols-[0.7fr_0.8fr_1.35fr_1.15fr_0.8fr_1fr_0.9fr] gap-4 border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid">
            <span># Pedido</span><span>Tipo</span><span>Mesa / cliente</span><span>Apertura</span><span>Total</span><span>Usuario / cajero</span><span class="text-right">Acción</span>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($orders as $order)
                <article class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-[0.7fr_0.8fr_1.35fr_1.15fr_0.8fr_1fr_0.9fr] xl:items-center xl:gap-4 xl:px-5" data-order-item data-order-type="{{ $order->type->value }}" data-order-searchable="{{ str($order->formattedNumber().' '.($order->restaurantTable?->name ?? '').' '.($order->customer_name ?? '').' '.($order->createdBy?->name ?? 'Sistema'))->lower() }}">
                    <div class="flex items-start justify-between gap-3 sm:col-span-2 xl:col-span-1 xl:block"><p class="text-base font-bold text-slate-950">{{ $order->formattedNumber() }}</p><p class="text-lg font-bold text-slate-950 tabular-nums xl:hidden">{{ \App\Support\UiFormatter::money($order->total) }}</p></div>
                    <div>@if ($order->restaurantTable)<span class="inline-flex items-center gap-2 rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">▤ Mesa</span>@else<span class="inline-flex items-center gap-2 rounded-xl bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-600">▢ Para llevar</span>@endif</div>
                    <div><p class="font-semibold text-slate-900">{{ $order->restaurantTable?->name ?? ($order->customer_name ?: 'Para llevar') }}</p>@if ($order->restaurantTable && $order->customer_name)<p class="mt-0.5 text-xs text-slate-500">{{ $order->customer_name }}</p>@endif</div>
                    <p class="whitespace-nowrap text-sm text-slate-600"><span class="mr-2" aria-hidden="true">◷</span>{{ \App\Support\UiFormatter::date($order->opened_at, true) }}</p>
                    <p class="hidden whitespace-nowrap text-lg font-bold text-slate-950 tabular-nums xl:block">{{ \App\Support\UiFormatter::money($order->total) }}</p>
                    <p class="text-sm text-slate-700"><span class="mr-2 text-slate-500" aria-hidden="true">●</span>{{ $order->createdBy?->name ?? 'Sistema' }}</p>
                    <a class="btn-primary w-full sm:col-span-2 xl:col-span-1 xl:min-w-32" href="{{ route('orders.show', $order->ulid) }}">Continuar <span class="ml-2" aria-hidden="true">→</span></a>
                </article>
            @empty
                <div class="empty-state">No hay pedidos abiertos.</div>
            @endforelse
        </div>
    </section>

    @include('orders._paid_history')
</div>

<script>
    (() => {
        const browser = document.querySelector('[data-orders-browser]');
        if (! browser) return;
        const search = browser.querySelector('[data-order-search]');
        const filters = [...browser.querySelectorAll('[data-order-filter]')];
        const items = [...browser.querySelectorAll('[data-order-item]')];
        const empty = browser.querySelector('[data-order-empty-filter]');
        let type = 'all';
        const refresh = () => {
            const term = search.value.trim().toLocaleLowerCase('es');
            let visible = 0;
            items.forEach((item) => {
                item.hidden = ! ((type === 'all' || item.dataset.orderType === type) && (! term || item.dataset.orderSearchable.includes(term)));
                if (! item.hidden) visible++;
            });
            empty.classList.toggle('hidden', visible > 0 || items.length === 0);
        };
        filters.forEach((filter) => filter.addEventListener('click', () => {
            type = filter.dataset.orderFilter;
            filters.forEach((button) => {
                const active = button === filter;
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                for (const className of ['border-orange-500', 'bg-orange-50', 'text-orange-600']) button.classList.toggle(className, active);
                for (const className of ['border-slate-200', 'bg-white', 'text-slate-700']) button.classList.toggle(className, ! active);
            });
            refresh();
        }));
        search.addEventListener('input', refresh);
    })();
</script>
@endsection
