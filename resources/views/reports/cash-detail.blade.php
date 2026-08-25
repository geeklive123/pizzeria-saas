@extends('layouts.app')
@section('title', 'Detalle de caja')
@section('heading', 'Reportes · Caja')
@section('content')
<div class="page-heading">
    <div>
        <a class="back-link" href="{{ route('reports.cash') }}">← Volver al reporte</a>
        <h1 class="mt-2">{{ $session->cashRegister->name }}</h1>
        <p class="text-sm font-semibold {{ $session->status === \App\Enums\CashSessionStatus::Open ? 'text-emerald-700' : 'text-stone-600' }}">
            {{ $session->branch->name }} · {{ $session->status === \App\Enums\CashSessionStatus::Open ? 'Turno abierto' : 'Turno cerrado' }}
        </p>
        <p>
            {{ \App\Support\UiFormatter::date($session->opened_at, true) }} · abrió {{ $session->openedBy->name }}
            @if ($session->closedBy) · cerró {{ $session->closedBy->name }} el {{ \App\Support\UiFormatter::date($session->closed_at, true) }} @endif
        </p>
        @if ($session->closing_balance_status)
            <div class='mt-3 rounded-xl bg-stone-100 p-3 text-sm'>
                <strong>{{ \App\Support\UiFormatter::cashClosingBalance($session->closing_balance_status) }}</strong>
                @if ($session->closing_observation)<span class='block text-stone-600'>{{ $session->closing_observation }}</span>@endif
            </div>
        @endif
    </div>
</div>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        ['Fondo inicial / contado', $session->opening_amount],
        ['Referencia heredada', $session->inherited_cash_amount ?? '0'],
        ['Diferencia de apertura', $session->opening_difference_amount ?? '0'],
        ['Ventas efectivo', $session->report_summary['cash_payments']],
        ['Ventas QR', $session->report_summary['qr_payments']],
        ['Ventas totales', $session->report_summary['sales_total']],
        ['Retiros propietario', $session->report_summary['owner_withdrawals']],
        ['Gastos CASH', $session->report_summary['expense_cash']],
        ['Otros ingresos', $session->report_summary['manual_in']],
        ['Otros egresos', $session->report_summary['manual_out']],
        ['Efectivo esperado', $session->status === \App\Enums\CashSessionStatus::Closed ? $session->expected_cash_amount : $session->report_summary['expected_cash']],
        ['Efectivo contado al cierre', $session->counted_cash_amount ?? '0'],
        ['Diferencia de cierre', $session->difference_amount ?? '0'],
    ] as $metric)
        <div class="metric-card"><p class="metric-label">{{ $metric[0] }}</p><p class="metric-value">{{ \App\Support\UiFormatter::money($metric[1]) }}</p></div>
    @endforeach
</section>

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <section class="card">
        <div class="card-header"><div><h2 class="card-title">Pagos individuales</h2><p class="card-subtitle">Efectivo y QR permanecen separados; no existe un método artificial mixto.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Fecha</th><th>Método</th><th>Monto</th><th>Usuario</th></tr></thead>
                <tbody>
                    @foreach ($session->payments as $payment)
                        <tr><td>{{ \App\Support\UiFormatter::date($payment->paid_at, true) }}</td><td>{{ \App\Support\UiFormatter::paymentMethod($payment->method) }}</td><td>{{ \App\Support\UiFormatter::money($payment->amount) }}</td><td>{{ $payment->receivedBy->name }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <section class="card">
        <div class="card-header"><div><h2 class="card-title">Movimientos físicos</h2><p class="card-subtitle">Los retiros se distinguen de gastos y otros movimientos.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Motivo</th><th>Registra / autoriza</th></tr></thead>
                <tbody>
                    @foreach ($session->movements as $movement)
                        <tr>
                            <td>{{ \App\Support\UiFormatter::date($movement->occurred_at, true) }}</td>
                            <td>{{ \App\Support\UiFormatter::cashMovement($movement->type) }}</td>
                            <td>{{ \App\Support\UiFormatter::money($movement->amount) }}</td>
                            <td>{{ $movement->reason }} @if($movement->observation)<small class="block text-stone-500">{{ $movement->observation }}</small>@endif</td>
                            <td>{{ $movement->createdBy->name }} @if($movement->authorizedBy)<small class="block text-stone-500">Autoriza: {{ $movement->authorizedBy->name }}</small>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
