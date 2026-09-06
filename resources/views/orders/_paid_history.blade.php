<section class="mt-5 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-emerald-100 bg-emerald-50/60 px-4 py-4 sm:px-5">
        <div class="flex min-w-0 items-center gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-emerald-500 text-xl font-bold text-white" aria-hidden="true">✓</span>
            <div class="min-w-0"><h2 class="text-lg font-bold text-slate-950">Pedidos pagados</h2><p class="text-sm text-slate-600">Historial disponible para consulta y reimpresión.</p></div>
        </div>
        <span class="shrink-0 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-700">{{ $paidOrders->total() }} {{ $paidOrders->total() === 1 ? 'pedido' : 'pedidos' }}</span>
    </div>
    <div class="hidden grid-cols-[0.65fr_0.75fr_1.15fr_1.05fr_0.75fr_0.9fr_0.7fr_2.35fr] gap-4 border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid">
        <span># Pedido</span><span>Tipo</span><span>Mesa / cliente</span><span>Cierre</span><span>Total</span><span>Usuario / cajero</span><span>Estado</span><span class="text-right">Acciones</span>
    </div>
    <div class="divide-y divide-slate-100">
        @forelse ($paidOrders as $order)
            <article class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-[0.65fr_0.75fr_1.15fr_1.05fr_0.75fr_0.9fr_0.7fr_2.35fr] xl:items-center xl:gap-4 xl:px-5" data-order-item data-order-type="{{ $order->type->value }}" data-order-searchable="{{ str($order->formattedNumber().' '.($order->restaurantTable?->name ?? '').' '.($order->customer_name ?? '').' '.($order->createdBy?->name ?? 'Sistema'))->lower() }}">
                <div class="flex items-start justify-between gap-3 sm:col-span-2 xl:col-span-1 xl:block"><a class="text-base font-bold text-slate-950 hover:text-orange-600" href="{{ route('orders.show', $order->ulid) }}">{{ $order->formattedNumber() }}</a><p class="text-lg font-bold text-slate-950 tabular-nums xl:hidden">{{ \App\Support\UiFormatter::money($order->total) }}</p></div>
                <div>@if ($order->restaurantTable)<span class="inline-flex items-center gap-2 rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">▤ Mesa</span>@else<span class="inline-flex items-center gap-2 rounded-xl bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-600">▢ Para llevar</span>@endif</div>
                <div><p class="font-semibold text-slate-900">{{ $order->restaurantTable?->name ?? ($order->customer_name ?: 'Para llevar') }}</p>@if ($order->restaurantTable && $order->customer_name)<p class="mt-0.5 text-xs text-slate-500">{{ $order->customer_name }}</p>@endif</div>
                <p class="whitespace-nowrap text-sm text-slate-600"><span class="mr-2" aria-hidden="true">◷</span>{{ \App\Support\UiFormatter::date($order->closed_at ?? $order->opened_at, true) }}</p>
                <p class="hidden whitespace-nowrap text-lg font-bold text-slate-950 tabular-nums xl:block">{{ \App\Support\UiFormatter::money($order->total) }}</p>
                <p class="text-sm text-slate-700"><span class="mr-2 text-slate-500" aria-hidden="true">●</span>{{ $order->createdBy?->name ?? 'Sistema' }}</p>
                <div><span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700"><span aria-hidden="true">✓</span>Pagado</span></div>
                <div class="grid gap-2 sm:col-span-2 sm:grid-cols-2 xl:col-span-1 xl:flex xl:flex-nowrap xl:justify-end">
                    @can('transferPayments', $order)<a class="btn-secondary w-full sm:col-span-2 xl:hidden" href="{{ route('orders.cash-session-transfer.create', $order->ulid) }}">Transferir a otra cajera</a>@endcan
                    @can('reprintKitchen', $order)<form method="POST" action="{{ route('orders.reprint.kitchen', $order->ulid) }}">@csrf<button class="btn-secondary w-full whitespace-nowrap"><span class="mr-2" aria-hidden="true">▣</span>Reimprimir cocina</button></form>@endcan
                    @can('reprintCustomerTicket', $order)<form method="POST" action="{{ route('orders.reprint.ticket', $order->ulid) }}">@csrf<button class="btn-secondary w-full whitespace-nowrap"><span class="mr-2" aria-hidden="true">▤</span>Reimprimir ticket cliente</button></form>@endcan
                    @can('transferPayments', $order)
                        <details class="relative hidden xl:block">
                            <summary class="btn-secondary cursor-pointer list-none px-3" aria-label="Más acciones">•••</summary>
                            <div class="absolute right-0 z-10 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                                <a class="block rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50" href="{{ route('orders.cash-session-transfer.create', $order->ulid) }}">Transferir a otra cajera</a>
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
