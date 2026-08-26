@extends('layouts.app')
@section('title', 'Cajas')
@section('heading', 'Caja')
@section('content')
<div class='page-heading'>
    <div><a class='back-link' href='{{ route('cash.index') }}'>← Volver a Caja</a><h1>Cajas de {{ request()->attributes->get('branch')->name }}</h1><p>Administra únicamente las cajas físicas de la sucursal activa.</p></div>
    <a class='btn-primary' href='{{ route('cash-registers.create') }}'>Crear caja</a>
</div>
<div class='card'>
    <div class='table-wrap'><table>
        <thead><tr><th>Nombre</th><th>Estado</th><th>Turno</th><th></th></tr></thead>
        <tbody>
        @forelse($registers as $register)
            <tr>
                <td class='font-medium'>{{ $register->name }}</td>
                <td><span class='badge {{ $register->is_active ? 'badge-success' : '' }}'>{{ $register->is_active ? 'Activa' : 'Inactiva' }}</span></td>
                <td>{{ $register->activeSession ? 'Abierto por '.$register->activeSession->openedBy->name : 'Sin turno abierto' }}</td>
                <td class='flex justify-end gap-2'>
                    <a class='btn-secondary' href='{{ route('cash-registers.edit', $register->ulid) }}'>Editar</a>
                    <form method='POST' action='{{ route('cash-registers.toggle', $register->ulid) }}'>@csrf
                        <button class='btn-secondary' @disabled($register->activeSession && $register->is_active)>{{ $register->is_active ? 'Desactivar' : 'Activar' }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan='4'><div class='empty-state'><p>No hay cajas configuradas en esta sucursal.</p><a class='btn-primary mt-4' href='{{ route('cash-registers.create', ['onboarding' => 1]) }}'>Crear Caja Principal</a></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if($registers->hasPages())<div class='border-t p-4'>{{ $registers->links() }}</div>@endif
</div>
@endsection
