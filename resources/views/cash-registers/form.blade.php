@extends('layouts.app')
@section('title', $register->exists ? 'Editar caja' : 'Crear caja')
@section('heading', 'Caja')
@section('content')
<div class='mx-auto max-w-3xl'>
    <div class='page-heading'><div><a class='back-link' href='{{ $onboarding ? route('cash.open.form') : route('cash-registers.index') }}'>← Volver</a><h1>{{ $register->exists ? 'Editar caja' : 'Crear caja' }}</h1><p>La caja quedará asociada a {{ request()->attributes->get('branch')->name }}.</p></div></div>
    <form class='card space-y-5 p-6' method='POST' action='{{ $register->exists ? route('cash-registers.update', $register->ulid) : route('cash-registers.store') }}'>
        @csrf @if($register->exists)@method('PUT')@endif
        @if($onboarding)<input type='hidden' name='onboarding' value='1'>@endif
        <div><label class='label' for='name'>Nombre</label><input class='input' id='name' name='name' maxlength='120' value='{{ old('name', $register->name) }}' required></div>
        <label class='flex items-center gap-3 rounded-xl bg-stone-50 p-4'><input type='checkbox' name='is_active' value='1' @checked(old('is_active', $register->is_active ?? true))><span><strong class='block text-sm'>Caja activa</strong><span class='text-xs text-stone-500'>Las cajas activas aparecen al abrir turno.</span></span></label>
        <button class='btn-primary w-full'>{{ $onboarding ? 'Crear y continuar a Abrir turno' : 'Guardar caja' }}</button>
    </form>
</div>
@endsection
