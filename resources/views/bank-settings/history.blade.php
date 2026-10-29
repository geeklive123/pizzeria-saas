@extends('layouts.app')
@section('title', 'Banco Económico · Historial de pagos QR')
@section('heading', 'Banco Económico')
@section('content')
@php($labels = ['matched' => 'Coincide con API', 'bank_only' => 'Pago bancario sin registro local', 'local_only' => 'Pago local sin evidencia bancaria suficiente', 'mismatch' => 'Diferencia de importe o identificador', 'review' => 'Requiere revisión', 'unverified' => 'Sin consulta bancaria aplicable', 'diagnostic_paid' => 'Pago de diagnóstico · revisar'])
<div class="space-y-5">
    <nav class="flex flex-wrap gap-3" aria-label="Banco Económico">
        <a class="btn-secondary" href="{{ route('bank-settings.index') }}">Configuración API</a>
        <a class="btn-secondary" href="{{ route('bank-settings.index', ['tab' => 'authentication']) }}">Probar autenticación</a>
        <a class="btn-secondary" href="{{ route('bank-qr-diagnostics.index') }}">Probar QR y conciliación</a>
        <a class="btn-secondary" href="{{ route('bank-qr-history.index') }}" aria-current="page">Historial de pagos QR</a>
    </nav>
    <section class="card p-5 space-y-4">
        <h1 class="text-xl font-bold">Historial de pagos QR</h1>
        <p class="text-sm">Las consultas son de solo lectura. Un diagnóstico no es una venta. Las fechas se muestran en hora de Bolivia. Un pago confirmado por API no demuestra un abono en cuenta.</p>
        @if(!$evidenceAvailable)<p class="text-amber-800">Registro de evidencia de abono no disponible: requiere una migración pendiente, previa autorización y respaldo.</p>@endif
        @if($errors->any())<p class="text-red-700" role="alert">{{ $errors->first() }}</p>@endif
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <p>Pagos locales confirmados <strong class="block">{{ $summary['confirmed'] }}</strong></p>
            <p>Importe confirmado BOB <strong class="block">{{ $summary['total'] }}</strong></p>
            <p>Coinciden con API <strong class="block">{{ $summary['matched'] }}</strong></p>
            <p>Banco sin pago local <strong class="block">{{ $summary['bank_only'] }}</strong></p>
            <p>Requieren revisión <strong class="block">{{ $summary['review'] }}</strong></p>
        </div>
    </section>
    <section class="card p-5 space-y-4">
        <h2 class="font-bold">Filtros</h2>
        <form method="GET" action="{{ route('bank-qr-history.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label>Desde<input class="input w-full" type="date" name="from" value="{{ $filters['from'] }}"></label>
            <label>Hasta<input class="input w-full" type="date" name="to" value="{{ $filters['to'] }}"></label>
            <label>Sucursal<select class="input w-full" name="branch"><option value="">Todas y sin sucursal identificada</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($filters['branch'] == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
            <label>Número de pedido<input class="input w-full" name="order" value="{{ $filters['order'] }}" inputmode="numeric"></label>
            <label>Referencia comercial<input class="input w-full" name="reference" value="{{ $filters['reference'] }}" maxlength="32"></label>
            <label>QR ID<input class="input w-full" name="qr" value="{{ $filters['qr'] }}" maxlength="190"></label>
            <label>Conciliación<select class="input w-full" name="state"><option value="">Todos</option>@foreach($labels as $value => $label)<option value="{{ $value }}" @selected($filters['state'] === $value)>{{ $label }}</option>@endforeach</select></label>
            <button class="btn-secondary self-end">Filtrar</button>
        </form>
        <p class="text-xs text-stone-600">Rango máximo: 32 días. Los movimientos bancarios sin sucursal identificada no se asignan a una sucursal por importe.</p>
    </section>
    <section class="card p-5 space-y-3">
        <h2 class="font-bold">Consultar pagos del banco</h2>
        <p class="text-sm">Consulta un día y una versión concreta de configuración. La respuesta es temporal y puede ser incompleta; la ausencia de un movimiento no demuestra que no exista. Confirma con el banco la cobertura de la lista y que la cuenta no sea compartida con otra empresa.</p>
        <form method="POST" action="{{ route('bank-qr-history.consult') }}" class="flex flex-wrap items-end gap-3">@csrf
            <label>Fecha<input class="input" type="date" name="date" value="{{ $filters['to'] }}" required></label>
            <label>Configuración bancaria<select class="input" name="setting_id" required><option value="">Seleccionar</option>@foreach($settings as $setting)<option value="{{ $setting->id }}">{{ $setting->branch_id ? 'Sucursal #'.$setting->branch_id : 'Empresa' }} · versión {{ $setting->id }} · {{ $setting->active_scope ? 'activa' : 'anterior' }}</option>@endforeach</select></label>
            <button class="btn-primary" type="submit">Consultar pagos del banco</button>
        </form>
        @if($snapshot)<p role="status">Consulta temporal: {{ $snapshot['date'] }} · configuración {{ $snapshot['setting_id'] }} · {{ count($snapshot['payments']) }} movimientos.</p>@endif
    </section>
    <section class="card p-5">
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Fecha y hora</th><th>Origen / pedido</th><th>Sucursal</th><th>Referencia / QR ID</th><th>Monto BOB</th><th>Banco / registro local</th><th>Conciliación</th><th>Detalle</th></tr></thead><tbody>
        @forelse($paginator as $row)
            @php($intent = $row['intent']) @php($diagnostic = $row['diagnostic'])
            <tr class="border-t align-top"><td class="py-3">{{ $row['date']?->timezone('America/La_Paz')->format('d/m/Y H:i:s') ?? 'No disponible' }}</td>
                <td>{{ $row['kind'] }} @if($intent) · {{ $intent->order?->formattedOperationalNumber() }} @if($intent->kitchenDispatch) · Tanda {{ $intent->kitchenDispatch->sequence_number }} @endif @endif</td>
                <td>{{ $branches->firstWhere('id', $row['branch_id'])?->name ?? 'No identificada' }}</td>
                <td>{{ $row['reference'] ?? 'No disponible' }}<br>{{ $row['qr_id'] ?? 'Sin QR ID' }}</td>
                <td>{{ $row['bank']['currency'] ?? 'BOB' }} {{ $row['amount'] }}</td><td>{{ $row['bank_status'] ?? 'Sin confirmación' }}<br>{{ $row['local_paid'] ? 'Payment confirmado' : 'Sin Payment confirmado' }}</td><td>{{ $labels[$row['state']] }}</td>
                <td><details><summary class="cursor-pointer font-semibold">Ver detalle</summary>
                    <div class="min-w-64 space-y-1 p-2">
                        <p>Generación: {{ ($intent?->created_at ?? $diagnostic?->created_at)?->timezone('America/La_Paz')->format('d/m/Y H:i:s') ?? 'No disponible' }}</p>
                        <p>Confirmación local del banco: {{ $intent?->confirmed_at?->timezone('America/La_Paz')->format('d/m/Y H:i:s') ?? 'No disponible' }}</p>
                        <p>Registro del pago: {{ $row['payment']?->paid_at?->timezone('America/La_Paz')->format('d/m/Y H:i:s') ?? 'No disponible' }}</p>
                        <p>Transacción bancaria: {{ $row['bank']['transactionId'] ?? ($intent?->reconciliation_data['transactionId'] ?? 'No disponible') }}</p>
                        <p>Estado local: {{ $intent?->status->value ?? $diagnostic?->status ?? 'Sin registro' }} · {{ $labels[$row['state']] }}</p>
                        <p>Pago confirmado por API: {{ $row['local_paid'] ? 'Sí, con datos validados' : ($intent?->bank_status === '1' ? 'El banco informó pago; requiere revisión' : ($diagnostic?->status === 'paid' ? 'Sí (diagnóstico)' : 'No confirmado')) }}</p>
                        <p>Payment registrado: {{ $row['local_paid'] ? 'Sí, completado' : 'No confirmado' }}</p>
                        @if($intent && $depositEvidence->has($intent->id))
                            <p class="font-semibold">Abono verificado por administrador mediante evidencia independiente</p>
                            @foreach($depositEvidence->get($intent->id) as $proof)
                                <p>Fecha de abono: {{ $proof->deposit_date }} · Registrado por usuario {{ $proof->user_id }} · {{ $proof->details }}</p>
                            @endforeach
                        @else
                            <p>Abono en cuenta: no verificado.</p>
                        @endif
                        @if($intent && $row['local_paid'] && $evidenceAvailable)
                            <form method="POST" action="{{ route('bank-qr-history.deposit', $intent->ulid) }}" class="space-y-2 border-t pt-2">@csrf
                                <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                                <label class="block">Fecha del abono<input class="input" type="date" name="deposit_date" required></label>
                                <label class="block">Evidencia independiente: referencia del extracto o caso bancario<textarea class="input w-full" name="details" minlength="20" maxlength="1000" required></textarea></label>
                                <label class="block"><input type="checkbox" name="confirmed" value="1" required> Confirmo que he contrastado este pago con evidencia independiente del abono en cuenta.</label>
                                <button class="btn-secondary">Registrar evidencia de abono</button>
                            </form>
                        @endif
                        <p>Última consulta: {{ $intent?->last_checked_at?->timezone('America/La_Paz')->format('d/m/Y H:i:s') ?? 'No disponible' }}</p>
                        @if($diagnostic)
                            @foreach($events->get($diagnostic->id, collect())->take(10) as $event)
                                <p>{{ \Carbon\CarbonImmutable::parse($event->created_at, 'UTC')->timezone('America/La_Paz')->format('d/m/Y H:i:s') }} · {{ $event->operation }} · {{ $event->status }}</p>
                            @endforeach
                        @else
                            <p>Los intentos de venta conservan la última consulta, no un historial detallado de consultas.</p>
                        @endif
                        @if($intent?->status === \App\Enums\DynamicQrStatus::Paid && $intent->has_image)
                            <p class="font-bold">QR PAGADO — SOLO CONSULTA</p><img class="w-48 max-w-full" src="{{ route('orders.dynamic-qr.image', [$intent->order->ulid, $intent->ulid]) }}" alt="Imagen histórica del QR pagado">
                        @endif
                        @if($diagnostic?->status === 'paid' && $diagnostic->has_image)
                            <p class="font-bold">QR DE DIAGNÓSTICO PAGADO — SOLO CONSULTA</p><img class="w-48 max-w-full" src="{{ route('bank-qr-diagnostics.image', $diagnostic->ulid) }}" alt="Imagen histórica del QR de diagnóstico pagado">
                        @endif
                        @if(($intent || $diagnostic) && $row['qr_id'])
                            <form method="POST" action="{{ route('bank-qr-history.status', ($intent ?? $diagnostic)->ulid) }}">@csrf<input type="hidden" name="kind" value="{{ $intent ? 'sale' : 'diagnostic' }}"><button class="btn-secondary">Consultar estado bancario</button></form>
                        @endif
                    </div></details></td></tr>
        @empty<tr><td colspan="8" class="py-5">No hay operaciones para estos filtros.</td></tr>@endforelse
        </tbody></table></div>
        <div class="mt-4">{{ $paginator->links() }}</div>
    </section>
</div>
@endsection
