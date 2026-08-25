@extends('layouts.app')

@section('title', $item->name)
@section('heading', 'Inventario')

@section('content')
<div class="page-heading">
    <div>
        <a class="back-link" href="{{ route('inventory.index') }}">← Volver al inventario</a>
        <h1>{{ $item->name }}</h1>
        <p>{{ $item->ingredient_id ? 'Ingrediente' : 'Producto de venta directa' }} · Unidad de control: {{ $item->unit->symbol }}</p>
    </div>
    <a class="btn-secondary" href="{{ route('inventory.movements', ['item' => $item->ulid]) }}">Ver historial completo</a>
</div>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="metric-card">
        <p class="metric-label">Stock físico</p>
        <p class="metric-value">{{ \App\Support\UiFormatter::quantity($item->physical_quantity, $item->unit->symbol) }}</p>
        <p class="metric-help">Incluye lotes vencidos</p>
    </div>
    <div class="metric-card">
        <p class="metric-label">Stock vencido</p>
        <p class="metric-value text-red-700">{{ \App\Support\UiFormatter::quantity($item->expired_quantity, $item->unit->symbol) }}</p>
        <p class="metric-help">Requiere retiro administrativo</p>
    </div>
    <div class="metric-card">
        <p class="metric-label">Reservado</p>
        <p class="metric-value">{{ \App\Support\UiFormatter::quantity($item->reserved_quantity, $item->unit->symbol) }}</p>
        <p class="metric-help">Comprometido por pedidos activos</p>
    </div>
    <div class="metric-card">
        <p class="metric-label">Disponible</p>
        <p class="metric-value">{{ \App\Support\UiFormatter::quantity($item->available_quantity, $item->unit->symbol) }}</p>
        <p class="metric-help">{{ \App\Support\UiFormatter::stockStatus($item->stock_status) }}</p>
    </div>
    <div class="metric-card">
        <p class="metric-label">Stock mínimo</p>
        <p class="metric-value">{{ $stock?->minimum_quantity !== null ? \App\Support\UiFormatter::quantity($stock->minimum_quantity, $item->unit->symbol) : '—' }}</p>
        <p class="metric-help">{{ $stock?->minimum_quantity !== null ? 'Alerta informativa' : 'No configurado' }}</p>
    </div>
    <div class="metric-card">
        <p class="metric-label">Costo promedio</p>
        <p class="metric-value">{{ \App\Support\UiFormatter::money($stock?->average_cost) }}</p>
        <p class="metric-help">Por {{ $item->unit->symbol }}</p>
    </div>
    <div class="metric-card">
        <p class="metric-label">Valor físico total</p>
        <p class="metric-value">{{ \App\Support\UiFormatter::money($item->display_value) }}</p>
    </div>
</section>

@if ($item->expired_quantity !== '0.000')
    <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <strong>{{ \App\Support\UiFormatter::quantity($item->expired_quantity, $item->unit->symbol) }}</strong>
        corresponde a lotes vencidos y no está disponible para uso. El stock físico no se reducirá hasta registrar su retiro.
    </div>
@endif

@can('update', $item)
    <form method="POST" action="{{ route('inventory.minimum.update', $item->ulid) }}" class="card mt-6 flex flex-col gap-3 p-5 sm:flex-row sm:items-end">
        @csrf
        @method('PUT')
        <div class="flex-1">
            <label class="label" for="minimum_quantity">Stock mínimo en {{ $item->unit->symbol }}</label>
            <input class="input" id="minimum_quantity" name="minimum_quantity" value="{{ old('minimum_quantity', $stock?->minimum_quantity) }}" inputmode="decimal" placeholder="No configurado">
            <p class="field-help">Déjalo vacío para desactivar la alerta. No bloquea movimientos.</p>
        </div>
        <button class="btn-secondary">Guardar mínimo</button>
    </form>
@endcan

<section class="card mt-6">
    <div class="card-header">
        <div>
            <h2 class="card-title">Lotes</h2>
            <p class="card-subtitle">Entradas físicas y control de caducidad</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Lote</th>
                    <th>Ingreso</th>
                    <th>Caducidad</th>
                    <th>Cantidad inicial</th>
                    <th>Restante</th>
                    <th>Costo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($item->inventoryBatches as $batch)
                    <tr>
                        <td class="font-mono text-xs">{{ $batch->ulid }}</td>
                        <td>{{ \App\Support\UiFormatter::date($batch->received_at, true) }}</td>
                        <td>
                            @if ($batch->expires_at)
                                <p>{{ \App\Support\UiFormatter::date($batch->expires_at) }}</p>
                                <span class="badge {{ $batch->expiration_status === \App\Enums\InventoryBatchStatus::Expired ? 'badge-danger' : ($batch->expiration_status === \App\Enums\InventoryBatchStatus::ExpiringSoon ? 'bg-amber-100 text-amber-800' : 'badge-success') }}">
                                    {{ \App\Support\UiFormatter::batchStatus($batch->expiration_status) }}
                                </span>
                            @else
                                <span class="text-stone-500">Sin caducidad</span>
                            @endif
                        </td>
                        <td>{{ \App\Support\UiFormatter::quantity($batch->quantity_received, $item->unit->symbol) }}</td>
                        <td class="font-semibold">{{ \App\Support\UiFormatter::quantity($batch->quantity_remaining, $item->unit->symbol) }}</td>
                        <td>{{ \App\Support\UiFormatter::money($batch->unit_cost) }}</td>
                        <td>
                            @if ($batch->expiration_status === \App\Enums\InventoryBatchStatus::Expired && $batch->quantity_remaining !== '0.000')
                                @can('update', $item)
                                    <details class="min-w-52">
                                        <summary class="cursor-pointer text-sm font-medium text-red-700">Retirar vencido</summary>
                                        <form method="POST" action="{{ route('inventory.batches.discard-expired', [$item->ulid, $batch->ulid]) }}" class="mt-2 space-y-2" onsubmit="return confirm('¿Registrar esta cantidad como merma de stock vencido?')">
                                            @csrf
                                            <label class="label">Cantidad ({{ $item->unit->symbol }})</label>
                                            <input class="input" name="quantity" value="{{ $batch->quantity_remaining }}" inputmode="decimal" required>
                                            <label class="label">Motivo</label>
                                            <input class="input" name="reason" value="Retiro de lote vencido" required>
                                            <button class="btn-danger w-full">Registrar merma</button>
                                        </form>
                                    </details>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state">No hay lotes para este artículo.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@can('update', $item)
    <section class="card mt-6 p-5 sm:p-7">
        <h2 class="card-title">Registrar movimiento</h2>
        <p class="card-subtitle">Cada operación genera un movimiento auditable; el saldo no se edita directamente.</p>
        <div class="mt-5 grid gap-4 lg:grid-cols-3">
            @if (! $hasOpeningMovement)
                <form method="POST" action="{{ route('inventory.operate', $item->ulid) }}" class="operation-card">
                    @csrf
                    <input type="hidden" name="operation" value="opening">
                    <h3>Stock inicial</h3>
                    <p>Primera carga para esta sucursal.</p>
                    <label class="label">Cantidad</label>
                    <input class="input" name="quantity" inputmode="decimal" required>
                    <label class="label">Unidad</label>
                    <select class="input" name="unit_id">
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected($unit->id === $item->unit_id)>{{ $unit->symbol }}</option>
                        @endforeach
                    </select>
                    <label class="label">Costo unitario (Bs)</label>
                    <input class="input" name="unit_cost" inputmode="decimal" required>
                    <label class="label">Fecha de ingreso</label>
                    <input class="input" type="datetime-local" name="received_at" value="{{ now('America/La_Paz')->format('Y-m-d\TH:i') }}">
                    <label class="label">Caducidad</label>
                    <input class="input" type="date" name="expires_at">
                    <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="no_expiration" value="1" checked> Sin caducidad</label>
                    <button class="btn-primary mt-3 w-full">Cargar stock inicial</button>
                </form>
            @endif

            <form method="POST" action="{{ route('inventory.operate', $item->ulid) }}" class="operation-card">
                @csrf
                <input type="hidden" name="operation" value="adjustment">
                <h3>Ajustar stock</h3>
                <p>Corrige diferencias de conteo.</p>
                <label class="label">Dirección</label>
                <select class="input" name="direction">
                    <option value="adjustment_in">Ajuste positivo</option>
                    <option value="adjustment_out">Ajuste negativo</option>
                </select>
                <label class="label">Cantidad</label>
                <input class="input" name="quantity" inputmode="decimal" required>
                <label class="label">Unidad</label>
                <select class="input" name="unit_id">
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected($unit->id === $item->unit_id)>{{ $unit->symbol }}</option>
                    @endforeach
                </select>
                <label class="label">Motivo</label>
                <input class="input" name="reason" required>
                <button class="btn-primary mt-3 w-full">Registrar ajuste</button>
            </form>

            <form method="POST" action="{{ route('inventory.operate', $item->ulid) }}" class="operation-card">
                @csrf
                <input type="hidden" name="operation" value="waste">
                <h3>Registrar merma</h3>
                <p>Descuenta stock utilizable dañado. Los vencidos se retiran desde su lote.</p>
                <label class="label">Cantidad</label>
                <input class="input" name="quantity" inputmode="decimal" required>
                <label class="label">Unidad</label>
                <select class="input" name="unit_id">
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected($unit->id === $item->unit_id)>{{ $unit->symbol }}</option>
                    @endforeach
                </select>
                <label class="label">Motivo</label>
                <input class="input" name="reason" required>
                <button class="btn-danger mt-3 w-full">Registrar merma</button>
            </form>
        </div>
    </section>
@endcan

<section class="card mt-6">
    <div class="card-header">
        <div>
            <h2 class="card-title">Movimientos recientes</h2>
            <p class="card-subtitle">Historial inmutable del artículo</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Entrada / salida</th>
                    <th>Cantidad</th>
                    <th>Costo</th>
                    <th>Usuario</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movements as $movement)
                    <tr>
                        <td>{{ \App\Support\UiFormatter::date($movement->occurred_at, true) }}</td>
                        <td>{{ \App\Support\UiFormatter::movement($movement->type) }}</td>
                        <td><span class="badge {{ $movement->direction() > 0 ? 'badge-success' : 'badge-danger' }}">{{ $movement->direction() > 0 ? 'Entrada' : 'Salida' }}</span></td>
                        <td>{{ \App\Support\UiFormatter::quantity($movement->quantity, $item->unit->symbol) }}</td>
                        <td>{{ $movement->unit_cost ? \App\Support\UiFormatter::money($movement->unit_cost) : '—' }}</td>
                        <td>{{ $movement->createdBy?->name ?: 'Sistema' }}</td>
                        <td>{{ $movement->reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state">Sin movimientos.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($movements->hasPages())
        <div class="border-t p-4">{{ $movements->links() }}</div>
    @endif
</section>
@endsection
