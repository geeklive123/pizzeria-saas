@extends('layouts.app')
@section('title', $category->exists ? 'Editar categoría' : 'Nueva categoría')
@section('heading', 'Categorías de gasto')
@section('content')
<div class="mx-auto max-w-3xl"><div class="page-heading"><div><a class="back-link" href="{{ route('expense-categories.index') }}">← Volver</a><h1 class="mt-2">{{ $category->exists ? 'Editar categoría' : 'Nueva categoría' }}</h1></div></div>
<form class="card space-y-5 p-6" method="POST" action="{{ $category->exists ? route('expense-categories.update',$category->ulid) : route('expense-categories.store') }}">@csrf @if($category->exists)@method('PUT')@endif<div><label class="label">Nombre</label><input class="input" name="name" value="{{ old('name',$category->name) }}" required></div><div><label class="label">Descripción</label><textarea class="input" name="description">{{ old('description',$category->description) }}</textarea></div><label class="flex items-center gap-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$category->is_active))><span>Activa</span></label><button class="btn-primary">Guardar categoría</button></form></div>
@endsection
