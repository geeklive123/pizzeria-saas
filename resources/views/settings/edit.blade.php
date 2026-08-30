@extends('layouts.app')
@section('title', 'Configuración')
@section('heading', 'Configuración')
@section('content')
<form method='POST' action='{{ route('settings.charge-mode') }}' class='card mb-6 p-5 sm:p-7'>@csrf @method('PUT')<h2 class='card-title'>Operacion de mesas</h2><label class='mt-4 block'><span class='label'>Modo de cobro en mesas</span><select class='input' name='table_charge_mode'>@foreach(\App\Enums\TableChargeMode::cases() as $mode)<option value='{{ $mode->value }}' @selected(old('table_charge_mode', $company->table_charge_mode->value) === $mode->value)>{{ $mode->label() }}</option>@endforeach</select></label><p class='mt-2 text-xs text-stone-500'>Configuracion global por empresa; solo afecta nuevas mesas.</p><button class='btn-primary mt-4'>Guardar modo de cobro</button></form>
<div class="page-heading"><div><h1>Configuración</h1><p>Datos de la empresa, sucursal e impresoras de la sede activa.</p></div></div>
<form method="POST" action="{{ route('settings.update') }}" class="space-y-6">@csrf @method('PUT')
<section class="card p-5 sm:p-7"><h2 class="card-title">Empresa</h2><div class="form-grid mt-5"><div><label class="label">Nombre comercial</label><input class="input" name="company[name]" value="{{ old('company.name',$company->name) }}" required></div><div><label class="label">Razón social</label><input class="input" name="company[legal_name]" value="{{ old('company.legal_name',$company->legal_name) }}"></div><div><label class="label">NIT</label><input class="input" name="company[tax_id]" value="{{ old('company.tax_id',$company->tax_id) }}"></div><div><label class="label">Teléfono</label><input class="input" name="company[phone]" value="{{ old('company.phone',$company->phone) }}"></div><div class="sm:col-span-2"><label class="label">Correo</label><input class="input" type="email" name="company[email]" value="{{ old('company.email',$company->email) }}"></div></div></section>
<section class="card p-5 sm:p-7"><h2 class="card-title">Sucursal: {{ $branch->name }}</h2><div class="form-grid mt-5"><div><label class="label">Nombre</label><input class="input" name="branch[name]" value="{{ old('branch.name',$branch->name) }}" required></div><div><label class="label">Teléfono</label><input class="input" name="branch[phone]" value="{{ old('branch.phone',$branch->phone) }}"></div><div class="sm:col-span-2"><label class="label">Dirección</label><textarea class="input min-h-24" name="branch[address]">{{ old('branch.address',$branch->address) }}</textarea></div></div></section>
<section class="card p-5 sm:p-7">
    <h2 class="card-title">Impresoras</h2>
    <p class="card-subtitle">Configuración lógica por sucursal. El nombre físico final se resuelve en el agente Windows y el puerto USB no se almacena.</p>
    <div class="mt-5 grid gap-3 sm:grid-cols-5">
        <div class="rounded-xl bg-stone-100 p-4"><p class="text-xs text-stone-500">Agente</p><strong>{{ $printAgentOnline ? 'En línea' : 'Fuera de línea' }}</strong></div>
        <div class="rounded-xl bg-stone-100 p-4"><p class="text-xs text-stone-500">Último contacto</p><strong class="text-sm">{{ $printAgent?->last_seen_at ? \App\Support\UiFormatter::date($printAgent->last_seen_at, true) : 'Sin contacto' }}</strong></div>
        <div class="rounded-xl bg-stone-100 p-4"><p class="text-xs text-stone-500">Última impresión</p><strong class="text-sm">{{ $printAgent?->last_printed_at ? \App\Support\UiFormatter::date($printAgent->last_printed_at, true) : 'Sin impresiones' }}</strong></div>
        <div class="rounded-xl bg-amber-50 p-4 text-amber-900"><p class="text-xs">Pendientes</p><strong>{{ $printStats['pending'] }}</strong></div>
        <div class="rounded-xl bg-red-50 p-4 text-red-900"><p class="text-xs">Con error</p><strong>{{ $printStats['failed'] }}</strong></div>
    </div>
    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        @foreach ([['key' => 'kitchen', 'title' => 'Cocina', 'setting' => $kitchenPrinter], ['key' => 'customer_ticket', 'title' => 'Ticket cliente/caja', 'setting' => $ticketPrinter]] as $printer)
            <div class="rounded-2xl border border-stone-200 p-5">
                <h3 class="font-semibold">{{ $printer['title'] }}</h3>
                <label class="mt-4 block"><span class="label">Nombre de impresora Windows</span><input class="input" name="printers[{{ $printer['key'] }}][windows_printer_name]" value="{{ old('printers.'.$printer['key'].'.windows_printer_name', $printer['setting']->windows_printer_name) }}" required></label>
                <div class="mt-4 flex flex-wrap gap-5 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="printers[{{ $printer['key'] }}][is_active]" value="1" @checked(old('printers.'.$printer['key'].'.is_active', $printer['setting']->is_active))> Activa</label>
                    @if ($printer['key'] === 'kitchen')
                        <label class="flex items-center gap-2"><input type="checkbox" name="printers[kitchen][auto_print]" value="1" @checked(old('printers.kitchen.auto_print', $kitchenPrinter->auto_print))> Imprimir automáticamente al enviar a cocina</label>
                    @endif
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <label><span class="label">Copias</span><input class="input" type="number" min="1" max="5" name="printers[{{ $printer['key'] }}][copies]" value="{{ old('printers.'.$printer['key'].'.copies', $printer['setting']->copies) }}" required></label>
                    <label><span class="label">Ancho</span><input class="input" value="80 mm" disabled></label>
                </div>
                @if ($printer['setting']->exists)
                    <button class="btn-secondary mt-4" type="submit" form="printer-test-{{ $printer['key'] }}">Imprimir prueba</button>
                @else
                    <p class="mt-4 text-xs text-stone-500">Guarda la configuración antes de imprimir una prueba.</p>
                @endif
            </div>
        @endforeach
    </div>
</section>
<div class="flex justify-end"><button class="btn-primary">Guardar configuración</button></div></form>
@foreach (['kitchen' => $kitchenPrinter, 'customer_ticket' => $ticketPrinter] as $key => $setting)
    @if ($setting->exists)
        <form id="printer-test-{{ $key }}" method="POST" action="{{ route('settings.printers.test', $key) }}">@csrf</form>
    @endif
@endforeach
@endsection
