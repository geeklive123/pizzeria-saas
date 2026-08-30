@extends('layouts.app')
@section('title', 'Venta')
@section('heading', 'Venta')
@section('header-subtitle', 'Gestiona mesas y pedidos del salón o para llevar')
@section('content')
<div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(310px,1fr)]">
    <div class="min-w-0 space-y-5">
        <section aria-label="Indicadores de venta" class="grid grid-cols-2 gap-3 2xl:grid-cols-4">
            <article class="sale-kpi">
                <span class="sale-kpi-icon bg-orange-500 text-white" aria-hidden="true">▦</span>
                <div><p class="sale-kpi-label">Mesas ocupadas</p><p class="sale-kpi-value">{{ $metrics['tables_occupied'] }} <span class="text-base font-medium text-stone-400">/ {{ $metrics['tables_total'] }}</span></p><p class="mt-1 text-xs font-semibold text-orange-600">{{ $metrics['occupancy_percentage'] }}% ocupación</p></div>
                <div class="col-span-2 mt-2 h-1.5 overflow-hidden rounded-full bg-stone-100"><div class="h-full rounded-full bg-orange-500" style="width: {{ $metrics['occupancy_percentage'] }}%"></div></div>
            </article>
            <article class="sale-kpi">
                <span class="sale-kpi-icon bg-emerald-500 text-white" aria-hidden="true">☷</span>
                <div><p class="sale-kpi-label">Pedidos abiertos</p><p class="sale-kpi-value">{{ $metrics['open_orders'] }}</p><p class="mt-1 text-xs font-semibold text-emerald-600">En progreso</p></div>
            </article>
            <article class="sale-kpi">
                <span class="sale-kpi-icon bg-violet-500 text-white" aria-hidden="true">Bs</span>
                <div><p class="sale-kpi-label">Ventas cobradas hoy</p><p class="sale-kpi-value text-xl">{{ \App\Support\UiFormatter::money($metrics['collected_today']) }}</p><p class="mt-1 text-xs font-semibold text-violet-600">{{ $metrics['collected_orders_today'] }} {{ $metrics['collected_orders_today'] === 1 ? 'pedido' : 'pedidos' }}</p></div>
            </article>
            <article class="sale-kpi">
                <span class="sale-kpi-icon bg-blue-500 text-white" aria-hidden="true">QR</span>
                <div><p class="sale-kpi-label">Cobrado QR hoy</p><p class="sale-kpi-value text-xl">{{ \App\Support\UiFormatter::money($metrics['qr_today']) }}</p><p class="mt-1 text-xs font-semibold text-blue-600">{{ $metrics['qr_payments_today'] }} {{ $metrics['qr_payments_today'] === 1 ? 'pago' : 'pagos' }}</p></div>
            </article>
        </section>

        <section>
            <span class="sr-only">En mesa</span>
            <div class="mb-4 flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <div><h1 class="text-lg font-bold tracking-tight text-slate-900">MESAS DEL SALÓN</h1><p class="mt-1 text-sm text-slate-500">Selecciona una mesa para abrir o continuar la cuenta.</p></div>
                <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-600" aria-label="Leyenda de estados">
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-emerald-500"></i>Libre</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-amber-400"></i>Ocupada</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-red-500"></i>Pendiente</span>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @forelse($tables as $table)
                    @if($table->openOrder)
                        @php($order = $table->openOrder)
                        @php($pending = (bool) $order->has_pending_payment)
                        @php($minutes = max(0, (int) $order->opened_at->diffInMinutes(now())))
                        <article class="sale-table-card {{ $pending ? 'border-red-200 bg-red-50/40' : 'border-amber-200 bg-amber-50/30' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div><h2 class="text-base font-bold text-slate-900">{{ $table->name }}</h2><p class="mt-1 text-xs text-slate-500">{{ $table->capacity ? $table->capacity.' personas' : 'Capacidad no configurada' }}</p></div>
                                <span class="badge {{ $pending ? 'badge-danger' : 'bg-amber-100 text-amber-700' }}">{{ $pending ? 'Pendiente' : 'Ocupada' }}</span>
                            </div>
                            <div class="mt-4 flex-1 space-y-1.5 text-sm">
                                <p class="truncate font-semibold text-slate-800">♙ {{ $order->customer_name ?: 'Cliente no registrado' }}</p>
                                <p class="text-xs font-semibold text-slate-600">{{ $order->formattedNumber() }}</p>
                                <p class="pt-1 text-lg font-bold text-red-600">{{ \App\Support\UiFormatter::money($order->pending_amount) }}</p>
                                <p class="text-xs text-slate-500">Hace {{ $minutes }} min</p>
                                @if($pending)
                                    <span class="mt-2 inline-flex rounded-lg bg-red-100 px-2.5 py-1 text-[11px] font-bold uppercase text-red-700">Pago pendiente</span>
                                @else
                                    <span class="mt-2 inline-flex rounded-lg bg-amber-100 px-2.5 py-1 text-[11px] font-bold uppercase text-amber-800">Cobro: {{ $order->charge_mode === \App\Enums\TableChargeMode::PerBatch ? 'Por tanda' : 'Al final' }}</span>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('tables.open', $table->ulid) }}" class="mt-4">
                                @csrf
                                <input type="hidden" name="charge_mode" value="{{ $order->charge_mode->value }}">
                                <button class="btn-primary w-full uppercase">Continuar pedido</button>
                            </form>
                        </article>
                    @else
                        <details class="sale-table-card group border-emerald-200 bg-emerald-50/25">
                            <summary class="cursor-pointer list-none">
                                <div class="flex items-start justify-between gap-2">
                                    <div><h2 class="text-base font-bold text-slate-900">{{ $table->name }}</h2><p class="mt-1 text-xs text-slate-500">{{ $table->capacity ? $table->capacity.' personas' : 'Capacidad no configurada' }}</p></div>
                                    <span class="badge badge-success">Libre</span>
                                </div>
                                <span class="btn-primary mt-5 w-full bg-emerald-600 group-open:hidden hover:bg-emerald-700">Abrir cuenta</span>
                            </summary>
                            <form method="POST" action="{{ route('tables.open', $table->ulid) }}" class="mt-4 space-y-3 border-t border-emerald-200 pt-4">
                                @csrf
                                <fieldset>
                                    <legend class="text-sm font-bold text-slate-800">¿Cómo se cobrará esta mesa?</legend>
                                    <div class="mt-2 space-y-2">
                                        @foreach(\App\Enums\TableChargeMode::cases() as $mode)
                                            <label class="flex min-h-10 cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold hover:border-orange-300">
                                                <input class="size-4 accent-orange-600" type="radio" name="charge_mode" value="{{ $mode->value }}" required>
                                                <span>{{ $mode === \App\Enums\TableChargeMode::PerBatch ? 'Por tanda' : 'Al final' }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <label class="block"><span class="label">Nombre / cliente (opcional)</span><input class="input" name="customer_name" maxlength="255" placeholder="Ej: Familia Pérez"></label>
                                <button class="btn-primary w-full uppercase">Abrir mesa</button>
                                <button type="button" class="w-full py-1 text-sm font-semibold text-slate-600 hover:text-slate-900" onclick="this.closest('details').removeAttribute('open')">Cancelar</button>
                            </form>
                        </details>
                    @endif
                @empty
                    <div class="empty-state card sm:col-span-2 xl:col-span-3 2xl:col-span-4">
                        <p>No hay mesas activas en esta sucursal.</p>
                        @can('create', \App\Models\RestaurantTable::class)
                            <a class="btn-primary mt-3 inline-flex" href="{{ route('tables.create') }}">Crear primera mesa</a>
                        @else
                            <p class="mt-2 text-sm">Solicita a un administrador que configure las mesas.</p>
                        @endcan
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <aside class="space-y-4 xl:sticky xl:top-24">
        <section class="card">
            <div class="p-5 pb-3"><h2 class="flex items-center gap-2 text-base font-bold uppercase text-slate-900"><span class="text-orange-600" aria-hidden="true">▱</span> Para llevar</h2><p class="mt-1 text-xs text-slate-500">Abre un pedido sin mesa.</p></div>
            <form class="space-y-4 px-5 pb-5" method="POST" action="{{ route('orders.takeaway.store') }}">
                @csrf
                <label class="block"><span class="label">Nombre / cliente</span><input class="input" name="customer_name" value="{{ old('customer_name') }}" placeholder="Ej: María López"></label>
                <label class="block"><span class="label">Teléfono</span><input class="input" name="customer_phone" value="{{ old('customer_phone') }}" inputmode="tel" placeholder="Ej: 71234567"></label>
                <label class="block"><span class="label">Notas (opcional)</span><textarea class="input min-h-20" name="notes" placeholder="Alguna indicación especial...">{{ old('notes') }}</textarea></label>
                <div>
                    <p class="label">Modo de cobro</p>
                    <div class="rounded-xl border border-orange-200 bg-orange-50 p-3"><p class="text-sm font-bold text-orange-700">Cobrar al confirmar pedido</p><p class="mt-1 text-xs text-orange-600">El pago se registra al confirmar y entregar el pedido.</p></div>
                </div>
                <button class="btn-primary w-full uppercase">Abrir pedido para llevar</button>
            </form>
        </section>

        <section class="card">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4"><h2 class="text-xs font-bold uppercase tracking-wide text-slate-800"><span class="mr-1 text-red-500">△</span> Inventario: próximos a agotarse</h2></div>
            <div class="divide-y divide-slate-100">
                @forelse($lowStockItems as $item)
                    @php($minimum = $item->inventoryStocks->first()->minimum_quantity)
                    <div class="flex items-center gap-3 px-5 py-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full {{ $item->sales_stock_level === 'low' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600' }}" aria-hidden="true">◇</span>
                        <div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-slate-800">{{ $item->name }}</p><p class="mt-0.5 text-[11px] text-slate-500">Mínimo: {{ \App\Support\UiFormatter::quantity($minimum, $item->unit->symbol) }}</p></div>
                        <p class="text-right text-xs font-bold {{ $item->sales_stock_level === 'low' ? 'text-red-600' : 'text-amber-600' }}">{{ \App\Support\UiFormatter::quantity($item->available_quantity, $item->unit->symbol) }}<span class="block text-[10px] font-medium">disponible</span></p>
                    </div>
                @empty
                    <p class="p-5 text-sm text-slate-500">No hay ítems por debajo o próximos al mínimo configurado.</p>
                @endforelse
            </div>
            @can('viewAny', \App\Models\InventoryItem::class)
                <div class="border-t border-slate-100 p-3"><a class="flex min-h-10 items-center justify-center text-sm font-bold text-blue-600 hover:text-blue-700" href="{{ route('inventory.index') }}">Ir a Inventario →</a></div>
            @endcan
        </section>
    </aside>
</div>
@endsection
