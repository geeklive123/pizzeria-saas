<section class="mt-5 overflow-visible rounded-2xl border border-emerald-200 bg-white shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-emerald-100 bg-emerald-50/60 px-4 py-4 sm:px-5">
        <div class="flex min-w-0 items-center gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-emerald-500 text-xl font-bold text-white" aria-hidden="true">✓</span>
            <div class="min-w-0"><h2 class="text-lg font-bold text-slate-950">Pedidos pagados</h2><p class="text-sm text-slate-600">Historial disponible para consulta y reimpresión.</p></div>
        </div>
        <span class="shrink-0 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-700">{{ $paidOrders->total() }} {{ $paidOrders->total() === 1 ? 'pedido' : 'pedidos' }}</span>
    </div>
    @if ($canFilterPaidOrders)
        <div class="border-b border-slate-200 bg-white px-4 py-4 sm:px-5">
            <div class="flex flex-wrap gap-2" aria-label="Filtrar pedidos pagados por fecha">
                @foreach ([['today', 'Hoy'], ['yesterday', 'Ayer'], ['week', 'Esta semana'], ['month', 'Este mes']] as [$preset, $label])
                    <a class="min-h-10 rounded-xl border px-4 py-2 text-sm font-semibold {{ $paidOrderRange->preset === $preset ? 'border-orange-500 bg-orange-50 text-orange-600' : 'border-slate-200 text-slate-700' }}" href="{{ route('orders.index', ['preset' => $preset]) }}">{{ $label }}</a>
                @endforeach
            </div>
            <form class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" method="GET" action="{{ route('orders.index') }}">
                <input type="hidden" name="preset" value="custom">
                <label><span class="label">Desde</span><input class="input" type="date" name="date_from" value="{{ $paidOrderRange->from->toDateString() }}" required></label>
                <label><span class="label">Hasta</span><input class="input" type="date" name="date_to" value="{{ $paidOrderRange->to->toDateString() }}" required></label>
                <button class="btn-secondary min-h-11">Rango personalizado</button>
            </form>
        </div>
    @else
        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
            <p class="font-semibold text-slate-900">Mostrando pedidos pagados de hoy</p>
            <p class="mt-1 text-sm text-slate-600">Puedes consultar y reimprimir los pedidos pagados del día actual.</p>
        </div>
    @endif
    <div class="hidden grid-cols-[minmax(5rem,0.65fr)_minmax(5rem,0.75fr)_minmax(7rem,1.15fr)_minmax(8rem,1.05fr)_minmax(6rem,0.75fr)_minmax(7rem,0.9fr)_minmax(5rem,0.7fr)_minmax(25rem,3fr)] gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 2xl:grid">
        <span># Pedido</span><span>Tipo</span><span>Mesa / cliente</span><span>Cierre</span><span class="text-right">Total</span><span>Creado por</span><span class="text-center">Estado</span><span class="text-right">Acciones</span>
    </div>
    <div class="divide-y divide-slate-100">
        @forelse ($paidOrders as $order)
            <article class="grid gap-3 p-4 sm:grid-cols-2 2xl:grid-cols-[minmax(5rem,0.65fr)_minmax(5rem,0.75fr)_minmax(7rem,1.15fr)_minmax(8rem,1.05fr)_minmax(6rem,0.75fr)_minmax(7rem,0.9fr)_minmax(5rem,0.7fr)_minmax(25rem,3fr)] 2xl:items-center 2xl:gap-3 2xl:px-5" data-order-item data-order-type="{{ $order->type->value }}" data-order-searchable="{{ str($order->formattedOperationalNumber().' '.($order->restaurantTable?->name ?? '').' '.($order->customer_name ?? '').' '.($order->createdBy?->name ?? 'Sistema'))->lower() }}">
                <div class="flex items-start justify-between gap-3 sm:col-span-2 2xl:col-span-1 2xl:block"><a class="text-base font-bold text-slate-950 hover:text-orange-600" href="{{ route('orders.show', $order->ulid) }}">{{ $order->formattedOperationalNumber() }}</a><p class="text-lg font-bold text-slate-950 tabular-nums 2xl:hidden">{{ \App\Support\UiFormatter::money($order->total) }}</p></div>
                <div>@if ($order->restaurantTable)<span class="inline-flex items-center gap-2 rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">▤ Mesa</span>@else<span class="inline-flex items-center gap-2 rounded-xl bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-600">▢ Para llevar</span>@endif</div>
                <div><p class="font-semibold text-slate-900">{{ $order->restaurantTable?->name ?? ($order->customer_name ?: 'Para llevar') }}</p>@if ($order->restaurantTable && $order->customer_name)<p class="mt-0.5 text-xs text-slate-500">{{ $order->customer_name }}</p>@endif</div>
                <p class="whitespace-nowrap text-sm text-slate-600"><span class="mr-2" aria-hidden="true">◷</span>{{ \App\Support\UiFormatter::date($order->closed_at ?? $order->opened_at, true) }}</p>
                <p class="hidden whitespace-nowrap text-right text-lg font-bold text-slate-950 tabular-nums 2xl:block">{{ \App\Support\UiFormatter::money($order->total) }}</p>
                <p class="whitespace-nowrap text-sm text-slate-700"><span class="mr-2 text-slate-500" aria-hidden="true">●</span>{{ $order->createdBy?->name ?? 'Sistema' }}</p>
                <div class="2xl:text-center"><span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700"><span aria-hidden="true">✓</span>Pagado</span></div>
                <div class="grid min-w-0 gap-2 sm:col-span-2 sm:grid-cols-2 2xl:col-span-1 2xl:flex 2xl:flex-nowrap 2xl:justify-end">
                    @can('transferPayments', $order)<a class="btn-secondary w-full sm:col-span-2 2xl:hidden" href="{{ route('orders.cash-session-transfer.create', $order->ulid) }}">Transferir a otra cajera</a>@endcan
                    @can('reprintKitchen', $order)<form method="POST" action="{{ route('orders.reprint.kitchen', $order->ulid) }}">@csrf<button class="btn-secondary w-full whitespace-nowrap"><span class="mr-2" aria-hidden="true">▣</span>Reimprimir cocina</button></form>@endcan
                    @can('reprintCustomerTicket', $order)<form method="POST" action="{{ route('orders.reprint.ticket', $order->ulid) }}">@csrf<button class="btn-secondary w-full whitespace-nowrap"><span class="mr-2" aria-hidden="true">▤</span>Reimprimir ticket cliente</button></form>@endcan
                    @can('transferPayments', $order)
                        <details class="relative z-20 hidden 2xl:block">
                            <summary class="grid size-10 cursor-pointer list-none place-items-center rounded-xl border border-slate-200 bg-white text-xl font-bold leading-none text-slate-700 shadow-sm transition hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500" aria-label="Más acciones" title="Más acciones"><span aria-hidden="true">…</span></summary>
                            <div class="absolute right-0 top-full z-30 mt-1 w-60 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                                <a class="flex items-center gap-2 rounded-lg bg-orange-50 px-3 py-2 text-left text-sm font-semibold text-orange-700 hover:bg-orange-100" href="{{ route('orders.cash-session-transfer.create', $order->ulid) }}"><span aria-hidden="true">↔</span>Transferir a otra cajera</a>
                            </div>
                        </details>
                    @endcan
                </div>
            </article>
        @empty
            <div class="empty-state">Todavía no hay pedidos pagados.</div>
        @endforelse
    </div>
    @if ($paidOrders->hasPages())<div class="border-t border-slate-100 p-4">{{ $paidOrders->links() }}</div>@endif
</section>
