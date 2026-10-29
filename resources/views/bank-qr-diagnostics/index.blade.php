@extends('layouts.app')
@section('title', 'Banco Económico · Probar QR')
@section('heading', 'Banco Económico · Probar QR')
@section('content')
<div class="space-y-6">
    <nav class="flex flex-wrap gap-3" aria-label="Banco Económico"><a class="btn-secondary" href="{{ route('bank-settings.index') }}">Configuración API</a><a class="btn-secondary" href="{{ route('bank-settings.index', ['tab' => 'authentication']) }}">Probar autenticación</a><a class="btn-secondary" href="{{ route('bank-qr-diagnostics.index') }}" aria-current="page">Probar QR y conciliación</a><a class="btn-secondary" href="{{ route('bank-qr-history.index') }}">Historial de pagos QR</a></nav>
    <section class="card p-6">
        <h1 class="text-xl font-bold">Probar QR</h1>
        <p class="mt-2 text-sm">Esta operación puede recibir dinero real y no registra ventas ni movimientos de caja. Un pago de prueba requiere conciliación contable independiente.</p>
        @if (! $enabled)<p class="mt-2 font-semibold">Pruebas bancarias desactivadas.</p>@endif
        @if ($blocker || $salesIncidents->isNotEmpty())<p class="mt-2 font-semibold text-amber-800" role="status">Existen operaciones anteriores pendientes de conciliación. Puedes generar una prueba nueva con una referencia distinta. Revisa las operaciones anteriores para evitar pagos no identificados.</p>@endif
        @foreach ($salesIncidents as $incident)<p class="text-sm">Operación de venta pendiente: {{ $incident->commercial_reference }} · Bs {{ $incident->amount }} · {{ $incident->status->value }}</p>@endforeach
        @if ($errors->any())<p class="mt-3 text-red-700" role="alert">{{ $errors->first() }}</p>@endif
        <form method="POST" action="{{ route('bank-qr-diagnostics.store') }}" class="mt-4 space-y-3">
            @csrf
            <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
            <input type="hidden" name="amount" value="1.00">
            <p>Importe de prueba: Bs 1,00</p>
            <label class="block"><input type="checkbox" name="confirm_generation" value="1" required> Confirmo que deseo generar una nueva prueba que puede recibir dinero real.</label>
            <button class="btn-primary" @disabled(! $enabled)>Generar QR de prueba</button>
        </form>
    </section>
    @foreach ($records as $record)
        <section class="card p-6 space-y-2">
            <p class="font-semibold">Estado: {{ $record->status }} · BOB {{ $record->amount }}</p>
            <p>Referencia: {{ $record->commercial_reference }}</p>
            <p>QR ID: {{ $record->bank_qr_id ?? 'No recibido' }}</p>
            <p>responseCode de generación: {{ $record->generation_response_code ?? 'No disponible' }}</p>
            <p>HTTP de generación: {{ $record->generation_http_status ?? 'No disponible' }}</p>
            @if ($record->original_qr_image !== null)
                @php($inspection = $inspections[$record->id])
                <p>Longitud original: {{ $inspection['length'] }} bytes</p>
                <p>Base64: {{ $inspection['base64_valid'] ? 'válido' : 'inválido' }} · Imagen: {{ $inspection['inspection_limited'] ? 'no inspeccionada por tamaño' : ($inspection['mime'] ?? 'no es PNG ni JPEG') }}</p>
                @if ($inspection['png_available'])
                    <img class="mx-auto w-full max-w-sm bg-white p-4" src="{{ route('bank-qr-diagnostics.image', $record->ulid) }}" alt="QR bancario de la referencia {{ $record->commercial_reference }}">
                @else
                    <p>La imagen QR PNG no está disponible o no es válida.</p>
                @endif
                <details>
                <summary class="cursor-pointer font-semibold">Ver Base64 original</summary>
                <label class="block"><span class="label">qrImage original recibido del banco, sin normalizar</span>
                    <textarea class="input w-full font-mono" rows="6" readonly autocomplete="off" spellcheck="false" data-bank-qr-original data-original-json="{{ json_encode($record->original_qr_image, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) }}">{{ $record->original_qr_image }}</textarea>
                </label>
                <button type="button" class="btn-secondary" data-bank-qr-copy>Copiar Base64 completo</button>
                <span class="text-sm" role="status" data-bank-qr-copy-status></span>
                </details>
            @else
                <p>La imagen QR y el Base64 original no están disponibles para esta operación.</p>
            @endif
            @if ($record->issue_code)<p role="alert">Etapa: {{ $record->operation ?? 'desconocida' }} · HTTP: {{ $record->http_status ?? 'sin respuesta' }} · responseCode: {{ $record->response_code ?? 'no disponible' }} · Código: {{ $record->issue_code }}</p>@endif
            @foreach ($evidence->get($record->id, collect()) as $item)
                <div class="rounded border p-3 text-sm">
                    <p>{{ $item->created_at }} · {{ $item->kind }} · Usuario {{ $item->user_id }}</p>
                    <p>{{ $item->details }}</p>
                    @if ($item->candidate_qr_id)<p>QR candidato: {{ $item->candidate_qr_id }} · Resultado API: {{ $item->bank_status ?? 'aún no consultado' }} · HTTP: {{ $item->http_status ?? 'no disponible' }} · responseCode: {{ $item->response_code ?? 'no disponible' }} · Código: {{ $item->issue_code ?? 'ninguno' }}</p>@endif
                </div>
            @endforeach
            @if ($record->status === 'generation_unknown' && ! $record->bank_qr_id)
                <div class="space-y-4 border-t pt-4">
                    <h2 class="font-bold">Conciliar generación incierta</h2>
                    <p class="text-sm">Documenta la respuesta externa del banco. Un QR candidato se consulta sin asociarlo automáticamente a esta referencia.</p>
                    <form method="POST" action="{{ route('bank-qr-diagnostics.reconcile', $record->ulid) }}" class="space-y-2">
                        @csrf<input type="hidden" name="action" value="evidence">
                        <label class="block"><span class="label">Contacto o canal del banco</span><input class="input" name="bank_contact" maxlength="120" required></label>
                        <label class="block"><span class="label">Número de caso o documento del banco</span><input class="input" name="bank_case" maxlength="120" required></label>
                        <label class="block"><span class="label">Fecha de la confirmación externa</span><input class="input" type="date" name="bank_date" required></label>
                        <label class="block"><span class="label">Confirmación del banco de que no se generó: fuente, fecha y detalle</span><textarea class="input w-full" name="details" minlength="20" maxlength="2000" required></textarea></label>
                        <button class="btn-secondary">Registrar confirmación externa</button>
                    </form>
                    <form method="POST" action="{{ route('bank-qr-diagnostics.reconcile', $record->ulid) }}" class="space-y-2">
                        @csrf<input type="hidden" name="action" value="verify">
                        <label class="block"><span class="label">QR ID informado por el banco</span><input class="input" name="candidate_qr_id" maxlength="190" required></label>
                        <label class="block"><span class="label">Fuente, fecha y evidencia de la referencia y del QR</span><textarea class="input w-full" name="details" minlength="20" maxlength="2000" required></textarea></label>
                        <button class="btn-secondary">Consultar QR candidato</button>
                    </form>
                    <form method="POST" action="{{ route('bank-qr-diagnostics.reconcile', $record->ulid) }}" class="space-y-2">
                        @csrf<input type="hidden" name="action" value="approve">
                        <label class="block"><span class="label">Motivo de autorización y referencia de la confirmación externa</span><textarea class="input w-full" name="details" minlength="20" maxlength="2000" required></textarea></label>
                        <label class="block"><input type="checkbox" name="confirmed" value="1" required> Confirmo que el banco verificó externamente que no se generó un QR y autorizo cerrar esta incidencia.</label>
                        <button class="btn-secondary">Autorizar cierre como no generada</button>
                    </form>
                </div>
            @endif
            @if ($record->bank_qr_id && ! in_array($record->status, ['paid', 'cancelled', 'confirmed_not_generated', 'failed'], true))
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('bank-qr-diagnostics.check', $record->ulid) }}">@csrf<button class="btn-secondary">Consultar estado</button></form>
                    <form method="POST" action="{{ route('bank-qr-diagnostics.cancel', $record->ulid) }}">@csrf<button class="btn-secondary">Solicitar cancelación</button></form>
                </div>
            @endif
        </section>
    @endforeach
</div>
@endsection
