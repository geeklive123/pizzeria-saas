@extends('layouts.app')
@section('title', 'Banco Económico · Configuración API')
@section('heading', 'Banco Económico')
@section('content')
<div class="space-y-6">
    <nav class="flex flex-wrap gap-3" aria-label="Banco Económico">
        <a class="btn-secondary" href="{{ route('bank-settings.index', ['scope' => $scope]) }}" @if ($tab === 'configuration') aria-current="page" @endif>Configuración API</a>
        <a class="btn-secondary" href="{{ route('bank-settings.index', ['scope' => $scope, 'tab' => 'authentication']) }}" @if ($tab === 'authentication') aria-current="page" @endif>Probar autenticación</a>
        <a class="btn-secondary" href="{{ route('bank-qr-diagnostics.index') }}">Probar QR y conciliación</a>
        <a class="btn-secondary" href="{{ route('bank-qr-history.index') }}">Historial de pagos QR</a>
    </nav>
    <div class="card p-5 flex flex-wrap gap-3 text-sm" aria-label="Estado de la configuración">
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ $complete ? 'Configuración completa' : 'Configuración incompleta' }}</span>
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ $effective ? 'Configuración guardada' : 'Sin configuración guardada' }}</span>
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ $effective?->enabled ? 'Configuración habilitada' : 'Configuración deshabilitada' }}</span>
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ config('baneco.enabled') ? 'Cobros operativos habilitados globalmente' : 'Cobros operativos deshabilitados' }}</span>
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ $authAvailable ? 'Autenticación disponible con confirmación' : 'Autenticación requiere credenciales válidas' }}</span>
        @if ($blocked)<span class="rounded-full bg-amber-100 px-3 py-1">Operaciones anteriores pendientes de conciliación</span>@endif
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ session('bank_auth_result.success') && session('bank_auth_result.setting_id') === $effective?->id ? 'Autenticación comprobada en esta sesión' : 'Credenciales pendientes de verificar' }}</span>
        <span class="rounded-full bg-stone-100 px-3 py-1">{{ $qrVerified ? 'Generación QR comprobada' : 'Generación QR pendiente de comprobar' }}</span>
    </div>
    @if ($tab === 'configuration')
    <section class="card p-6 space-y-4">
        <h1 class="text-xl font-bold">Configuración API</h1>
        <p>Las versiones anteriores conservan sus credenciales para consultar operaciones pendientes.</p>
        <div class="flex gap-3"><a class="btn-secondary" href="{{ route('bank-settings.index', ['scope' => 'company']) }}">Empresa</a><a class="btn-secondary" href="{{ route('bank-settings.index', ['scope' => 'branch']) }}">Sucursal actual</a></div>
        <p class="font-semibold">Ámbito: {{ $scope === 'branch' ? 'Sucursal actual' : 'Empresa' }}</p>
        @if ($scope === 'branch' && ! $setting)<p>Sin configuración propia: se utiliza la configuración de empresa.</p>@endif
        @if (! config('baneco.enabled'))<p>El interruptor global de cobros QR está desactivado.</p>@endif
        @if ($blocked)<p role="status" class="text-amber-800">Existen operaciones anteriores pendientes de conciliación. Puedes generar una prueba nueva con una referencia distinta. Revisa las operaciones anteriores para evitar pagos no identificados.</p>@endif
        @if ($errors->any())<div role="alert" class="text-red-700">{{ $errors->first() }}</div>@endif
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <form method="POST" action="{{ route('bank-settings.store') }}" class="space-y-4" autocomplete="off">
            @csrf<input type="hidden" name="scope" value="{{ $scope }}">
            <input type="hidden" name="enabled" value="0">
            <label class="block"><input type="checkbox" name="enabled" value="1" @checked((bool) old('enabled', $effective?->enabled ?? false))> Habilitar configuración bancaria para este ámbito</label>
            <label class="block"><span class="label">Ambiente bancario</span><select class="input" name="environment"><option value="certification" @selected(old('environment', $environment) === 'certification')>Certificación</option><option value="production" @selected(old('environment', $environment) === 'production')>Producción</option></select></label>
            <label class="block"><span class="label">URL API del ambiente seleccionado</span><select class="input" name="base_url">
                @foreach (\App\Actions\SaveBancoEconomicoSettingAction::URLS as $name => $allowed)<option value="{{ $allowed }}" @selected(old('base_url', $url) === $allowed)>{{ $name === 'production' ? 'Producción' : 'Certificación' }} · {{ $allowed }}</option>@endforeach
            </select></label>
            <p class="text-sm">Las credenciales y cuentas de certificación y producción pueden ser diferentes. Confirma el ambiente con el banco.</p>
            @foreach (['username' => 'Usuario API', 'password' => 'Contraseña API', 'aes_key' => 'Llave AES', 'account_credit' => 'Cuenta de abono'] as $key => $label)
                <div class="rounded border p-3 space-y-2">
                    <p class="font-semibold">{{ $label }} · {{ $configured[$key] ? 'Configurado' : 'Sin configurar' }}</p>
                    <label class="block"><input type="checkbox" name="replace_{{ $key }}" value="1"> Sustituir {{ $label }}</label>
                    <input class="input w-full" type="password" name="{{ $key }}" value="" autocomplete="new-password" aria-label="Nuevo valor de {{ $label }}">
                    <p class="text-sm">Deja sin marcar para conservar el valor existente.</p>
                </div>
            @endforeach
            <label class="block"><span class="label">Código de sucursal bancario (opcional)</span><input class="input" name="branch_code" maxlength="5" value="{{ old('branch_code', $branchCode) }}"></label>
            <label class="block"><span class="label">Vencimiento en días</span><input class="input" type="number" name="due_days" min="1" max="30" value="{{ old('due_days', $effective?->due_days ?? 1) }}" required></label>
            <label class="block"><input type="checkbox" name="technical_logging" value="1" @checked(old('technical_logging', $effective?->technical_logging ?? true))> Registro técnico sanitizado</label>
            <button class="btn-primary">Guardar configuración</button>
        </form>
    </section>
    @else
    <section class="card p-6 space-y-3">
        <h2 class="font-bold">Probar autenticación</h2>
        <p>Se contactará únicamente el ambiente guardado. Obtener un token no confirma que la cuenta de abono esté autorizada para generar QR.</p>
        @if (session('bank_auth_result') && session('bank_auth_result.setting_id') === $effective?->id)
            @php($result = session('bank_auth_result'))
            <p role="status">Autenticación {{ $result['success'] ? 'correcta' : 'fallida' }} · Ambiente: {{ $result['environment'] }} · Fecha: {{ $result['tested_at'] }} (La Paz) · Etapa: {{ $result['operation'] }} · HTTP: {{ $result['http_status'] ?? 'sin respuesta' }} · responseCode: {{ $result['response_code'] ?? 'no disponible' }} · Código: {{ $result['issue'] ?? 'ninguno' }}</p>
            @if (! $result['success'])<p>{{ match (true) { ($result['issue'] ?? '') === 'bank_unreachable' => 'No se pudo conectar con el banco o se agotó el tiempo de espera.', ($result['operation'] ?? '') === 'encrypt' => 'No se pudo completar el cifrado bancario.', str_starts_with($result['issue'] ?? '', 'bank_response_') => 'El banco rechazó la solicitud; verifica las credenciales y el ambiente.', str_starts_with($result['issue'] ?? '', 'bank_http_') => 'El banco devolvió un error HTTP.', default => 'No se pudo comprobar la autenticación. Revisa la etapa y el código indicados.' } }}</p>@endif
        @endif
        @if ($errors->any())<div role="alert" class="text-red-700">{{ $errors->first() }}</div>@endif
        <div class="flex flex-wrap gap-3"><a class="btn-secondary" href="{{ route('bank-settings.index', ['scope' => 'company', 'tab' => 'authentication']) }}">Empresa</a><a class="btn-secondary" href="{{ route('bank-settings.index', ['scope' => 'branch', 'tab' => 'authentication']) }}">Sucursal actual</a></div>
        <p>Ámbito: {{ $scope === 'branch' ? 'Sucursal actual' : 'Empresa' }} · Ambiente guardado: {{ match ($environment) { 'production' => 'Producción', 'certification' => 'Certificación', default => 'URL no reconocida' } }}</p>
        <form method="POST" action="{{ route('bank-settings.authenticate') }}" class="space-y-3">
            @csrf<input type="hidden" name="scope" value="{{ $scope }}">
            <label class="block"><input type="checkbox" name="confirm_contact" value="1" required> Confirmo que deseo contactar el ambiente bancario guardado.</label>
            <button class="btn-secondary" @disabled(! $effective)>Probar conexión y autenticación</button>
        </form>
    </section>
    @endif
</div>
@endsection
