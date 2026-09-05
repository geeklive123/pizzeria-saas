@extends('layouts.app')
@section('title', 'Caja')
@section('heading', 'Caja')
@section('content')
@if (! $session)
    <div class='mx-auto max-w-4xl'>
        <div class='page-heading'><div><h1>Caja</h1><p>No tienes un turno abierto a tu nombre en esta sucursal.</p></div>@can('create', $cashRegisterClass)<a class='btn-secondary' href='{{ route('cash-registers.index') }}'>Administrar cajas</a>@endcan</div>
        <div class='card p-8 text-center'>
            <div class='text-5xl'>Bs</div><h2 class='mt-5 text-2xl font-semibold'>Abre tu turno antes de cobrar</h2>
            <p class='mt-2 text-stone-500'>Cada cobro queda asociado a la caja física, la cajera y su turno.</p>
            @can('viewAny', $cashRegisterClass)<a class='btn-primary mt-6' href='{{ route('cash.open.form') }}'>Ver cajas y abrir turno</a>@endcan
        </div>
    </div>
@else
    <div class='page-heading'><div><p class='text-sm font-semibold text-emerald-700'>Tu turno está abierto</p><h1>{{ $session->cashRegister->name }}</h1><p>Desde {{ $formatter::date($session->opened_at, true) }} &middot; {{ $session->openedBy->name }}</p></div></div>

    <section class='grid gap-4 sm:grid-cols-2 xl:grid-cols-4'>
        <div class='metric-card'><p class='metric-label'>Fondo inicial</p><p class='metric-value'>{{ $formatter::money($session->opening_amount) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Ventas efectivo</p><p class='metric-value'>{{ $formatter::money($summary['cash_payments']) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Ventas QR</p><p class='metric-value'>{{ $formatter::money($summary['qr_payments']) }}</p><p class='metric-help'>No aumenta el efectivo físico.</p></div>
        <div class='metric-card'><p class='metric-label'>Ventas totales</p><p class='metric-value'>{{ $formatter::money($summary['sales_total']) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Ingresos manuales</p><p class='metric-value'>{{ $formatter::money($summary['manual_in']) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Egresos manuales</p><p class='metric-value'>{{ $formatter::money($summary['manual_out']) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Gastos en efectivo</p><p class='metric-value'>{{ $formatter::money($summary['expense_cash']) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Retiros propietario</p><p class='metric-value'>{{ $formatter::money($summary['owner_withdrawals']) }}</p></div>
        <div class='metric-card'><p class='metric-label'>Efectivo esperado</p><p class='metric-value'>{{ $formatter::money($summary['expected_cash']) }}</p></div>
        @if ($session->inherited_cash_amount !== null)
            <div class='metric-card'><p class='metric-label'>Referencia heredada</p><p class='metric-value'>{{ $formatter::money($session->inherited_cash_amount) }}</p><p class='metric-help'>Diferencia al recibir: {{ $formatter::money($session->opening_difference_amount) }}</p></div>
        @endif
    </section>

    @if ($canCreateExpense)
        <section class='card mt-6 p-6'>
            <h2 class='card-title'>Registrar egreso</h2>
            <p class='card-subtitle'>Los gastos en efectivo reducen esta caja. Los pagos QR, transferencia u otro medio no modifican el efectivo físico.</p>
            <form class='mt-5 space-y-4' method='POST' action='{{ route('cash.expenses.store') }}'>
                @csrf
                <div class='form-grid'>
                    <div class='sm:col-span-2'>
                        <label class='label' for='cash_expense_description'>Descripción</label>
                        <input class='input' id='cash_expense_description' name='description' maxlength='500' value='{{ old('description') }}' required>
                    </div>
                    <div>
                        <label class='label' for='cash_expense_amount'>Monto</label>
                        <input class='input' id='cash_expense_amount' name='amount' inputmode='decimal' value='{{ old('amount') }}' required>
                    </div>
                    <div>
                        <label class='label' for='cash_expense_payment_method'>Método de pago</label>
                        <select class='input' id='cash_expense_payment_method' name='payment_method' required>
                            <option value='cash' @selected(old('payment_method', 'cash') === 'cash')>Efectivo</option>
                            <option value='qr' @selected(old('payment_method') === 'qr')>QR</option>
                            <option value='transfer' @selected(old('payment_method') === 'transfer')>Transferencia</option>
                            <option value='other' @selected(old('payment_method') === 'other')>Otro</option>
                        </select>
                    </div>
                </div>
                <button class='btn-primary w-full'>Registrar egreso</button>
            </form>
        </section>
    @endif

    <div class='mt-6 grid gap-6 xl:grid-cols-2'>
        <section class='card p-6'>
            <h2 class='card-title'>Movimiento manual de efectivo</h2>
            <p class='card-subtitle'>Solo para fondos adicionales, salidas excepcionales, ajustes físicos o depósitos autorizados. Las ventas se cobran en Pedidos, las compras en Compras y los gastos en Gastos; sus efectos se reflejan automáticamente.</p>
            @can('update', $session)
                <form class='mt-5 space-y-4' method='POST' action='{{ route('cash.movements.store') }}'>
                    @csrf
                    <label class='block'><span class='label'>Tipo</span><select class='input' name='type' required><option value='manual_in'>Ingreso adicional</option><option value='manual_out'>Salida operativa excepcional</option></select></label>
                    <label class='block'><span class='label'>Monto</span><input class='input' name='amount' inputmode='decimal' required></label>
                    <label class='block'><span class='label'>Motivo</span><input class='input' name='reason' maxlength='500' required></label>
                    <button class='btn-secondary w-full'>Registrar movimiento manual</button>
                </form>
            @else
                <div class='mt-5 rounded-xl bg-stone-50 p-4 text-sm text-stone-600'>Puedes consultar todos los movimientos del turno, pero no tienes permiso para registrar movimientos manuales.</div>
            @endcan
        </section>

        <section class='card p-6'>
            <h2 class='card-title'>Cerrar turno</h2>
            <div class='mt-4 space-y-2 text-sm'>
                <p class='flex justify-between'><span>Fondo inicial</span><strong>{{ $formatter::money($session->opening_amount) }}</strong></p>
                <p class='flex justify-between'><span>Ventas efectivo</span><strong>{{ $formatter::money($summary['cash_payments']) }}</strong></p>
                <p class='flex justify-between'><span>Ventas QR</span><strong>{{ $formatter::money($summary['qr_payments']) }}</strong></p>
                <p class='flex justify-between'><span>Ventas totales</span><strong>{{ $formatter::money($summary['sales_total']) }}</strong></p>
                <p class='flex justify-between'><span>Ingresos manuales</span><strong>{{ $formatter::money($summary['manual_in']) }}</strong></p>
                <p class='flex justify-between'><span>Egresos manuales</span><strong>&minus; {{ $formatter::money($summary['manual_out']) }}</strong></p>
                <p class='flex justify-between'><span>Gastos en efectivo</span><strong>&minus; {{ $formatter::money($summary['expense_cash']) }}</strong></p>
                <p class='flex justify-between'><span>Retiros propietario</span><strong>&minus; {{ $formatter::money($summary['owner_withdrawals']) }}</strong></p>
                <p class='flex justify-between border-t pt-3 text-lg'><span>Efectivo esperado</span><strong>{{ $formatter::money($summary['expected_cash']) }}</strong></p>
            </div>
            @can('close', $session)
                <form class='mt-5 space-y-3' method='POST' action='{{ route('cash.close') }}' data-close-cash data-expected-cents='{{ \Brick\Math\BigDecimal::of($summary['expected_cash'])->multipliedBy(100)->toBigInteger() }}'>
                    @csrf
                    <label class='block'><span class='label'>Efectivo contado</span><input class='input' name='counted_cash_amount' inputmode='decimal' required data-counted-cash></label>
                    <div class='rounded-xl bg-stone-50 p-3 text-sm' aria-live='polite'>
                        <p class='flex justify-between'><span>Diferencia</span><strong data-cash-difference>Bs 0,00</strong></p>
                        <p class='mt-1 font-semibold' data-cash-balance-status>Ingresa el efectivo contado</p>
                    </div>
                    <label class='block'><span class='label'>Observación del cierre</span><textarea class='input' name='closing_observation' rows='2' maxlength='1000' data-closing-observation></textarea><small class='text-stone-500'>Obligatoria si existe faltante o sobrante.</small></label>
                    <p class='text-xs text-stone-500'>La diferencia se audita; no se alteran ventas ni movimientos para cuadrarla.</p>
                    <button class='btn-danger w-full'>Cerrar caja</button>
                </form>
            @endcan
        </section>
    </div>

    <script>
        (() => {
            const form = document.querySelector('[data-close-cash]');
            if (! form) return;
            const input = form.querySelector('[data-counted-cash]');
            const observation = form.querySelector('[data-closing-observation]');
            const differenceOutput = form.querySelector('[data-cash-difference]');
            const statusOutput = form.querySelector('[data-cash-balance-status]');
            const expected = BigInt(form.dataset.expectedCents);
            const cents = (value) => {
                const normalized = value.trim().replace(',', '.');
                if (! /^\d+(?:\.\d{0,2})?$/.test(normalized)) return null;
                const [whole, fraction = ''] = normalized.split('.');
                return BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
            };
            const money = (value) => {
                const negative = value < 0n;
                const absolute = negative ? -value : value;
                return 'Bs ' + (negative ? '-' : '') + (absolute / 100n).toString() + ',' + (absolute % 100n).toString().padStart(2, '0');
            };
            const refresh = () => {
                const counted = cents(input.value);
                if (counted === null) {
                    differenceOutput.textContent = 'Bs 0,00';
                    statusOutput.textContent = 'Ingresa un monto válido';
                    observation.required = false;
                    return;
                }
                const difference = counted - expected;
                differenceOutput.textContent = money(difference);
                statusOutput.textContent = difference === 0n ? 'CUADRADA' : (difference < 0n ? 'FALTANTE' : 'SOBRANTE');
                statusOutput.className = 'mt-1 font-semibold ' + (difference === 0n ? 'text-emerald-700' : 'text-red-700');
                observation.required = difference !== 0n;
            };
            input.addEventListener('input', refresh);
            form.addEventListener('submit', (event) => {
                if (! window.confirm('¿Cerrar este turno de caja?')) event.preventDefault();
            });
            refresh();
        })();
    </script>
    <section class='card mt-6'>
        <div class='card-header'><div><h2 class='card-title'>Movimientos de efectivo</h2><p class='card-subtitle'>Ledger inmutable y de solo lectura. Explica cada cambio del efectivo esperado.</p></div></div>
        <div class='table-wrap'>
            <table>
                <thead><tr><th>Fecha / hora</th><th>Tipo</th><th>Motivo / observación</th><th>Registra / autoriza</th><th>Entrada</th><th>Salida</th><th>Saldo acumulado</th></tr></thead>
                <tbody>
                    @forelse ($session->movements->sortBy(fn ($movement) => [$movement->occurred_at, $movement->id]) as $movement)
                        <tr>
                            <td>{{ $formatter::date($movement->occurred_at, true) }}</td>
                            <td>{{ $formatter::cashMovement($movement->type) }}</td>
                            <td>{{ $movement->reason ?: '—' }} @if($movement->observation)<small class='block text-stone-500'>{{ $movement->observation }}</small>@endif</td>
                            <td>{{ $movement->createdBy->name }} @if($movement->authorizedBy)<small class='block text-stone-500'>Autoriza: {{ $movement->authorizedBy->name }}</small>@endif</td>
                            <td class='font-semibold text-emerald-700'>{{ $movement->type->direction() > 0 ? $formatter::money($movement->amount) : '—' }}</td>
                            <td class='font-semibold text-red-700'>{{ $movement->type->direction() < 0 ? $formatter::money($movement->amount) : '—' }}</td>
                            <td class='font-semibold'>{{ $formatter::money($movementBalances[$movement->id]) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan='7'><div class='empty-state'>Aún no existen movimientos en este turno.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif

@if (request()->user()->canForCompany(\App\Enums\Permission::AuthorizeCashWithdrawals, request()->attributes->get('company')) && $activeSessions->isNotEmpty())
    <section class='card mt-6 p-6'>
        <h2 class='card-title'>Retiro del propietario</h2>
        <p class='card-subtitle'>Es una salida de efectivo separada y auditable. No crea un gasto ni modifica ventas.</p>
        <div class='mt-5 grid gap-5 xl:grid-cols-2'>
            @foreach ($activeSessions as $activeSession)
                <form class='rounded-2xl border border-stone-200 p-4' method='POST' action='{{ route('cash.withdrawals.store', $activeSession->ulid) }}'>
                    @csrf
                    <input type='hidden' name='idempotency_key' value='{{ $withdrawalIdempotencyKeys[$activeSession->id] }}'>
                    <h3 class='font-semibold'>{{ $activeSession->cashRegister->name }}</h3>
                    <p class='text-sm text-stone-500'>Turno de {{ $activeSession->openedBy->name }} &middot; {{ $formatter::date($activeSession->opened_at, true) }}</p>
                    <div class='mt-4 space-y-3'>
                        <label class='block'><span class='label'>Monto</span><input class='input' name='amount' inputmode='decimal' required></label>
                        <label class='block'><span class='label'>Motivo</span><input class='input' name='reason' maxlength='500' required></label>
                        <label class='block'><span class='label'>Observación</span><textarea class='input' name='observation' rows='2' maxlength='1000'></textarea></label>
                        <button class='btn-danger w-full'>Autorizar y registrar retiro</button>
                    </div>
                </form>
            @endforeach
        </div>
    </section>
@endif
@endsection
