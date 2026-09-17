@extends('layouts.app')
@section('title', 'Caja')
@section('heading', 'Caja')
@section('content')
<div class='mx-auto min-w-0 max-w-[1280px] text-slate-900 [&_.card-title]:text-slate-950 [&_.card-subtitle]:text-xs [&_.card-subtitle]:leading-relaxed [&_.label]:text-slate-700'>
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
    <div class='mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center'>
        <div><h1 class='text-3xl font-bold tracking-tight text-slate-950'>Caja</h1><p class='mt-1 text-sm text-slate-600'>Control del turno actual</p></div>
        <div class='min-w-0 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600'>
            <p class='break-words font-semibold text-slate-900'>{{ $session->cashRegister->name }} &middot; {{ $session->openedBy->name }}</p>
            <p class='mt-1'><span class='mr-1 inline-block size-2 rounded-full bg-emerald-500' aria-hidden='true'></span>Tu turno está abierto &middot; Desde {{ $formatter::date($session->opened_at, true) }}</p>
        </div>
    </div>

    @php
        // Presentation metadata only; amounts come directly from the existing session summary.
        $cashMetrics = [
            ['Fondo inicial', $session->opening_amount, 'Bs', 'bg-orange-50 text-orange-600'],
            ['Ventas efectivo', $summary['cash_payments'], 'Bs', 'bg-emerald-50 text-emerald-600'],
            ['Ventas QR', $summary['qr_payments'], '▦', 'bg-blue-50 text-blue-600'],
            ['Ventas totales', $summary['sales_total'], '▥', 'bg-orange-50 text-orange-600'],
            ['Ingresos manuales', $summary['manual_in'], '＋', 'bg-emerald-50 text-emerald-600'],
            ['Egresos manuales', $summary['manual_out'], '−', 'bg-rose-50 text-rose-600'],
            ['Gastos en efectivo', $summary['expense_cash'], '≡', 'bg-slate-100 text-slate-600'],
            ['Retiros propietario', $summary['owner_withdrawals'], '◎', 'bg-blue-50 text-blue-600'],
            ['Efectivo esperado', $summary['expected_cash'], 'Bs', 'bg-orange-100 text-orange-600'],
        ];
    @endphp
    <section class='grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-20' aria-label='Resumen del turno'>
        @foreach ($cashMetrics as [$label, $amount, $icon, $tone])
            <div class='flex min-w-0 items-center gap-3 rounded-xl border p-4 shadow-sm {{ $loop->iteration <= 5 ? 'xl:col-span-4' : 'xl:col-span-5' }} {{ $loop->last ? 'border-orange-200 bg-orange-50/70' : 'border-slate-200 bg-white' }}'>
                <span class='grid size-10 shrink-0 place-items-center rounded-xl text-xl font-semibold {{ $tone }}' aria-hidden='true'>{{ $icon }}</span>
                <div class='min-w-0'><p class='text-xs font-medium text-slate-600'>{{ $label }}</p><p class='mt-1 break-words text-xl font-bold tracking-tight text-slate-950 tabular-nums'>{{ $formatter::money($amount) }}</p></div>
            </div>
        @endforeach
    </section>
    @if ($session->inherited_cash_amount !== null)
        <div class='mt-3 flex flex-wrap gap-x-5 gap-y-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600'>
            <p>Referencia heredada <strong class='text-slate-900'>{{ $formatter::money($session->inherited_cash_amount) }}</strong></p>
            <p>Diferencia al recibir: {{ $formatter::money($session->opening_difference_amount) }}</p>
        </div>
    @endif

    <div class='mt-4 grid min-w-0 items-start gap-4 lg:grid-cols-2 lg:grid-rows-[auto_1fr]'>
    @if ($canCreateExpense)
        <section class='card min-w-0 p-4 sm:p-5 lg:col-start-1 lg:row-start-1'>
            <h2 class='card-title flex items-center gap-3'><span class='grid size-9 shrink-0 place-items-center rounded-full bg-rose-100 text-xl text-rose-600' aria-hidden='true'>−</span>Registrar egreso</h2>
            <p class='card-subtitle'>Los gastos en efectivo reducen el dinero disponible en la caja actual.</p>
            <form class='mt-5 space-y-4' method='POST' action='{{ route('cash.expenses.store') }}'>
                @csrf
                <div class='grid gap-4 sm:grid-cols-2'>
                    <div class='sm:col-span-2'>
                        <label class='label' for='cash_expense_description'>Descripción <span class='text-red-600' aria-hidden='true'>*</span></label>
                        <input class='input' id='cash_expense_description' name='description' placeholder='Ej. Compra de insumos, pago de servicio, etc.' maxlength='500' value='{{ old('description') }}' required>
                    </div>
                    <div>
                        <label class='label' for='cash_expense_amount'>Monto <span class='text-red-600' aria-hidden='true'>*</span></label>
                        <span class='relative block'><span class='pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500' aria-hidden='true'>Bs</span><input class='input pl-10' placeholder='0,00' id='cash_expense_amount' name='amount' inputmode='decimal' value='{{ old('amount') }}' required></span>
                    </div>
                    <div>
                        <label class='label' for='cash_expense_payment_method'>Método de pago <span class='text-red-600' aria-hidden='true'>*</span></label>
                        <select class='input' id='cash_expense_payment_method' name='payment_method' required>
                            <option value='cash' @selected(old('payment_method', 'cash') === 'cash')>Efectivo</option>
                            <option value='qr' @selected(old('payment_method') === 'qr')>QR</option>
                            <option value='transfer' @selected(old('payment_method') === 'transfer')>Transferencia</option>
                            <option value='other' @selected(old('payment_method') === 'other')>Otro</option>
                        </select>
                    </div>
                </div>
                <p class='text-xs text-slate-500'>QR, transferencia y otros medios no modifican el efectivo físico.</p>
                <button class='btn-primary min-h-11 w-full'>Registrar egreso</button>
            </form>
        </section>
    @endif

        <section class='card min-w-0 p-4 sm:p-5 lg:col-start-2 lg:row-span-2 lg:row-start-1'>
            <h2 class='card-title flex items-center gap-3'><span class='grid size-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-xl text-indigo-600' aria-hidden='true'>✓</span>Cerrar turno</h2>
            <p class='card-subtitle'>Revisa el resumen del turno y completa el cierre de caja.</p>
            <div class='mt-4 space-y-2 text-sm text-slate-600 [&_strong]:shrink-0 [&_strong]:text-slate-950 [&_strong]:tabular-nums [&_p]:gap-3'>
                <p class='flex justify-between'><span>Fondo inicial</span><strong>{{ $formatter::money($session->opening_amount) }}</strong></p>
                <p class='flex justify-between'><span>Ventas efectivo</span><strong>{{ $formatter::money($summary['cash_payments']) }}</strong></p>
                <p class='flex justify-between'><span>Ventas QR</span><strong>{{ $formatter::money($summary['qr_payments']) }}</strong></p>
                <p class='flex justify-between'><span>Ventas totales</span><strong>{{ $formatter::money($summary['sales_total']) }}</strong></p>
                <p class='flex justify-between'><span>Ingresos manuales</span><strong>{{ $formatter::money($summary['manual_in']) }}</strong></p>
                <p class='flex justify-between'><span>Egresos manuales</span><strong>&minus; {{ $formatter::money($summary['manual_out']) }}</strong></p>
                <p class='flex justify-between'><span>Gastos en efectivo</span><strong>&minus; {{ $formatter::money($summary['expense_cash']) }}</strong></p>
                <p class='flex justify-between'><span>Retiros propietario</span><strong>&minus; {{ $formatter::money($summary['owner_withdrawals']) }}</strong></p>
                <p class='flex justify-between border-t border-slate-300 pt-3 text-base font-bold text-slate-950'><span>Efectivo esperado</span><strong>{{ $formatter::money($summary['expected_cash']) }}</strong></p>
            </div>
            @can('close', $session)
                <form class='mt-5 space-y-3' method='POST' action='{{ route('cash.close') }}' data-close-cash data-expected-cents='{{ \Brick\Math\BigDecimal::of($summary['expected_cash'])->multipliedBy(100)->toBigInteger() }}'>
                    @csrf
                    <div class='grid items-start gap-3 sm:grid-cols-[1.2fr_1fr]'>
                    <label class='block'><span class='label'>Efectivo contado <span class='text-red-600' aria-hidden='true'>*</span></span><span class='relative block'><span class='pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500' aria-hidden='true'>Bs</span><input class='input pl-10' placeholder='0,00' name='counted_cash_amount' inputmode='decimal' required data-counted-cash></span></label>
                    <div class='rounded-xl bg-slate-100 p-3 text-xs text-slate-600' aria-live='polite'>
                        <p><span class='block'>Diferencia</span><strong class='mt-1 block text-lg text-slate-950 tabular-nums' data-cash-difference>Bs 0,00</strong></p>
                        <p class='mt-1 font-semibold' data-cash-balance-status>Ingresa el efectivo contado</p>
                    </div>
                    </div>
                    <label class='block'><span class='label'>Observación del cierre</span><textarea class='input' name='closing_observation' placeholder='Observación del cierre…' rows='2' maxlength='1000' data-closing-observation></textarea><small class='text-stone-500'>Obligatoria si existe faltante o sobrante.</small></label>
                    <p class='text-xs text-stone-500'>La diferencia se audita; no se alteran ventas ni movimientos para cuadrarla.</p>
                    @if (app(\App\Support\CompanyContext::class)->membership()->role === \App\Enums\MembershipRole::Cashier)
                        <p class='rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900'>Al cerrar la caja también se cerrará tu sesión.</p>
                    @endif
                    <button class='btn-danger min-h-11 w-full'>Cerrar caja</button>
                </form>
            @endcan
        </section>
        <section class='card min-w-0 p-4 sm:p-5 lg:col-start-1 lg:row-start-2 lg:self-stretch'>
            <h2 class='card-title flex items-center gap-3'><span class='grid size-9 shrink-0 place-items-center rounded-full bg-blue-100 text-xl text-blue-600' aria-hidden='true'>↔</span>Movimiento manual de efectivo</h2>
            <p class='card-subtitle'>Solo para fondos adicionales, retiros o ajustes. Las ventas se registran automáticamente.</p>
            @can('update', $session)
                <form class='mt-4 grid gap-4 sm:grid-cols-3' method='POST' action='{{ route('cash.movements.store') }}'>
                    @csrf
                    <label class='block'><span class='label'>Tipo <span class='text-red-600' aria-hidden='true'>*</span></span><select class='input' name='type' required><option value='manual_in'>Ingreso adicional</option><option value='manual_out'>Salida operativa excepcional</option></select></label>
                    <label class='block'><span class='label'>Monto <span class='text-red-600' aria-hidden='true'>*</span></span><span class='relative block'><span class='pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500' aria-hidden='true'>Bs</span><input class='input pl-10' placeholder='0,00' name='amount' inputmode='decimal' required></span></label>
                    <label class='block'><span class='label'>Motivo <span class='text-red-600' aria-hidden='true'>*</span></span><input class='input' name='reason' maxlength='500' required></label>
                    <button class='btn-secondary min-h-11 w-full sm:col-span-3'>Registrar movimiento manual</button>
                </form>
            @else
                <div class='mt-5 rounded-xl bg-stone-50 p-4 text-sm text-stone-600'>Puedes consultar todos los movimientos del turno, pero no tienes permiso para registrar movimientos manuales.</div>
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
                if (! window.confirm('¿Cerrar este turno de caja? Al cerrar la caja también se cerrará tu sesión.')) event.preventDefault();
            });
            refresh();
        })();
    </script>
    <section class='card mt-4 min-w-0'>
        <div class='card-header'><div><h2 class='card-title flex items-center gap-3'><span class='grid size-9 shrink-0 place-items-center rounded-xl bg-orange-100 text-orange-600' aria-hidden='true'>Bs</span>Movimientos de efectivo</h2><p class='card-subtitle'>Historial de movimientos del turno actual.</p></div></div>
        <div class='table-wrap'>
            <table class='[&_td]:px-4 [&_td]:py-3 [&_th]:px-4 [&_th]:text-[10px]'>
                <thead><tr><th>Fecha / hora</th><th>Tipo</th><th>Motivo / observación</th><th>Registra / autoriza</th><th class='text-right'>Entrada</th><th class='text-right'>Salida</th><th class='text-right'>Saldo acumulado</th></tr></thead>
                <tbody>
                    @forelse ($session->movements->sortBy(fn ($movement) => [$movement->occurred_at, $movement->id]) as $movement)
                        <tr>
                            <td>{{ $formatter::date($movement->occurred_at, true) }}</td>
                            <td>{{ $formatter::cashMovement($movement->type) }}</td>
                            <td>{{ $movement->reason ?: '—' }} @if($movement->observation)<small class='block text-stone-500'>{{ $movement->observation }}</small>@endif</td>
                            <td>{{ $movement->createdBy->name }} @if($movement->authorizedBy)<small class='block text-stone-500'>Autoriza: {{ $movement->authorizedBy->name }}</small>@endif</td>
                            <td class='whitespace-nowrap text-right font-semibold text-emerald-700 tabular-nums'>{{ $movement->type->direction() > 0 ? $formatter::money($movement->amount) : '—' }}</td>
                            <td class='whitespace-nowrap text-right font-semibold text-red-700 tabular-nums'>{{ $movement->type->direction() < 0 ? $formatter::money($movement->amount) : '—' }}</td>
                            <td class='whitespace-nowrap text-right font-bold text-slate-950 tabular-nums'>{{ $formatter::money($movementBalances[$movement->id]) }}</td>
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
    <section class='card mt-4 p-4 sm:p-5'>
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
                        <label class='block'><span class='label'>Monto <span class='text-red-600' aria-hidden='true'>*</span></span><span class='relative block'><span class='pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500' aria-hidden='true'>Bs</span><input class='input pl-10' placeholder='0,00' name='amount' inputmode='decimal' required></span></label>
                        <label class='block'><span class='label'>Motivo <span class='text-red-600' aria-hidden='true'>*</span></span><input class='input' name='reason' maxlength='500' required></label>
                        <label class='block'><span class='label'>Observación</span><textarea class='input' name='observation' rows='2' maxlength='1000'></textarea></label>
                        <button class='btn-danger w-full'>Autorizar y registrar retiro</button>
                    </div>
                </form>
            @endforeach
        </div>
    </section>
@endif
</div>
@endsection
