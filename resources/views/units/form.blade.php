@extends('layouts.app')
@section('title', $unit->exists ? 'Editar unidad' : 'Nueva unidad')
@section('heading', 'Unidades')
@section('content')
<div class='mx-auto max-w-3xl'><div class='page-heading'><div><a class='back-link' href='{{ route('units.index') }}'>← Volver</a><h1>{{ $unit->exists ? 'Editar unidad' : 'Nueva unidad' }}</h1><p>El tipo debe coincidir con la magnitud que se convertirá en inventario.</p></div></div>
<form class='card space-y-5 p-6' method='POST' action='{{ $unit->exists ? route('units.update', $unit->id) : route('units.store') }}'>@csrf @if($unit->exists)@method('PUT')@endif
<div class='form-grid'><div><label class='label' for='name'>Nombre</label><input class='input' id='name' name='name' maxlength='255' value='{{ old('name', $unit->name) }}' required></div><div><label class='label' for='symbol'>Símbolo</label><input class='input' id='symbol' name='symbol' maxlength='20' value='{{ old('symbol', $unit->symbol) }}' required></div>
<div class='sm:col-span-2'><label class='label' for='type'>Tipo</label><select class='input' id='type' name='type' required>@foreach($types as $type)<option value='{{ $type->value }}' @selected(old('type', $unit->type instanceof \App\Enums\UnitType ? $unit->type->value : $unit->type) === $type->value)>{{ match($type) { \App\Enums\UnitType::Weight => 'Peso', \App\Enums\UnitType::Volume => 'Volumen', \App\Enums\UnitType::Unit => 'Unidad' } }}</option>@endforeach</select><p class='field-help'>El tipo no puede cambiar cuando la unidad ya está en uso.</p></div></div>
<label class='flex items-center gap-3'><input type='checkbox' name='is_active' value='1' @checked(old('is_active', $unit->is_active ?? true))><span>Unidad activa</span></label>
<button class='btn-primary'>Guardar unidad</button></form></div>
@endsection
