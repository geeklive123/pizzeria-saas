@extends('layouts.app')
@section('title', 'Elegir sucursal')
@section('heading', 'Sucursal activa')
@section('content')
<div class="mx-auto max-w-2xl"><div class="card p-7"><h1 class="text-2xl font-semibold">Elige una sucursal</h1><p class="mt-2 text-sm text-stone-500">El inventario y las compras se mostrarán para esta ubicación.</p><div class="mt-6 grid gap-3 sm:grid-cols-2">@forelse($branches as $branch)<form method="POST" action="{{ route('context.branch.select') }}">@csrf<input type="hidden" name="branch" value="{{ $branch->ulid }}"><button class="w-full rounded-2xl border border-stone-200 p-5 text-left hover:border-orange-300 hover:bg-orange-50"><span class="block font-semibold">{{ $branch->name }}</span><span class="mt-1 block text-sm text-stone-500">{{ $branch->address ?: 'Sin dirección registrada' }}</span></button></form>@empty<p>No hay sucursales activas.</p>@endforelse</div></div></div>
@endsection
