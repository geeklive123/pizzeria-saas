@extends('layouts.app')

@section('title', 'Abrir caja')
@section('heading', 'Caja')

@section('content')
<div class="mx-auto max-w-3xl">
    <div class="page-heading">
        <div>
            <a class="back-link" href="{{ route('cash.index') }}">← Caja</a>
            <h1>Abrir turno</h1>
            <p>La nueva cajera debe contar y confirmar el efectivo recibido. El QR inicia siempre en Bs 0,00.</p>
        </div>
    </div>

    @if ($registers->isEmpty())
        <div class="card p-6">
            <h2 class="card-title">No hay cajas activas</h2>
            <p class="card-subtitle mt-2">Un owner o admin debe configurar una caja activa para esta sucursal antes de abrir un turno.</p>
        </div>
    @else
        <div class="mb-5 grid gap-3">
            @foreach ($registers as $register)
                <div class="rounded-2xl border p-4 {{ $register->activeSession ? 'border-amber-300 bg-amber-50' : 'border-stone-200 bg-white' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <strong>{{ $register->name }}</strong>
                            @if ($register->activeSession)
                                <p class="mt-1 text-sm text-amber-900">
                                    Ocupada por {{ $register->activeSession->openedBy->name }}
                                    desde {{ \App\Support\UiFormatter::date($register->activeSession->opened_at, true) }}.
                                </p>
                            @elseif ($register->latestClosedSession)
                                <p class="mt-1 text-sm text-stone-600">
                                    Referencia heredada del turno anterior:
                                    <strong>{{ \App\Support\UiFormatter::money($register->latestClosedSession->counted_cash_amount) }}</strong>.
                                    Debes contarla; no se copiará automáticamente.
                                </p>
                            @else
                                <p class="mt-1 text-sm text-stone-600">Primer turno: el efectivo contado será el fondo inicial, no una venta.</p>
                            @endif
                        </div>
                        <span class="text-sm font-semibold {{ $register->activeSession ? 'text-amber-800' : 'text-emerald-700' }}">
                            {{ $register->activeSession ? 'Ocupada' : 'Disponible' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <form class="card space-y-5 p-6" method="POST" action="{{ route('cash.open') }}">
            @csrf
            <label class="block">
                <span class="label">Caja disponible</span>
                <select class="input" name="register" required>
                    @foreach ($registers as $register)
                        <option value="{{ $register->ulid }}" @disabled($register->activeSession) @selected(old('register') === $register->ulid)>
                            {{ $register->name }}{{ $register->activeSession ? ' · ocupada' : '' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="label">Efectivo contado y confirmado</span>
                <input class="input" name="opening_amount" value="{{ old('opening_amount', '0.00') }}" inputmode="decimal" required>
                <small class="mt-1 block text-stone-500">En el primer turno es el fondo inicial. En un relevo se comparará con la referencia heredada y se guardará la diferencia.</small>
            </label>
            <div class="rounded-xl bg-sky-50 p-3 text-sm text-sky-900">
                <strong>QR inicial: Bs 0,00.</strong> Los pagos QR del turno anterior nunca se heredan.
            </div>
            <label class="block">
                <span class="label">Observación opcional</span>
                <textarea class="input" name="notes" rows="3">{{ old('notes') }}</textarea>
            </label>
            <button class="btn-primary w-full">Confirmar efectivo y abrir turno</button>
        </form>
    @endif
</div>
@endsection
