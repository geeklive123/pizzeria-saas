@extends('layouts.app')
@section('title', $category->exists ? 'Editar categoría' : 'Nueva categoría')
@section('heading', 'Categorías')
@section('content')
<div class='mx-auto max-w-3xl'><div class='page-heading'><div><a class='back-link' href='{{ route('categories.index') }}'>← Volver</a><h1>{{ $category->exists ? 'Editar categoría' : 'Nueva categoría' }}</h1></div></div>
<form class='card space-y-5 p-6' method='POST' action='{{ $category->exists ? route('categories.update', $category->ulid) : route('categories.store') }}'>@csrf @if($category->exists)@method('PUT')@endif
<div><label class='label' for='name'>Nombre</label><input class='input' id='name' name='name' maxlength='255' value='{{ old('name', $category->name) }}' required></div>
<div><label class='label' for='description'>Descripción</label><textarea class='input' id='description' name='description' rows='3'>{{ old('description', $category->description) }}</textarea></div>
<div><label class='label' for='sort_order'>Orden</label><input class='input' id='sort_order' name='sort_order' type='number' min='0' value='{{ old('sort_order', $category->sort_order ?? 0) }}' required><p class='field-help'>Los números menores aparecen primero.</p></div>
<label class='flex items-center gap-3'><input type='checkbox' name='is_active' value='1' @checked(old('is_active', $category->is_active ?? true))><span>Categoría activa</span></label>
<button class='btn-primary'>Guardar categoría</button></form></div>
@endsection
