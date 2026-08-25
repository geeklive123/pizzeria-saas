@extends('layouts.app')
@section('title', 'Usuarios')
@section('heading', 'Usuarios')
@section('content')
<div class="page-heading"><div><h1>Usuarios</h1><p>Membresías y roles de la empresa activa.</p></div>@can('create',\App\Models\Membership::class)<a class="btn-primary" href="{{ route('memberships.create') }}">+ Nuevo usuario</a>@endcan</div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>@forelse($memberships as $membership)<tr><td class="font-semibold">{{ $membership->user->name }}</td><td>{{ $membership->user->email }}</td><td>{{ \App\Support\UiFormatter::role($membership->role) }}</td><td><span class="badge {{ $membership->is_active ? 'badge-success' : '' }}">{{ $membership->is_active ? 'Activo':'Inactivo' }}</span></td><td class="text-right">@can('update',$membership)<a class="btn-secondary" href="{{ route('memberships.edit',$membership->id) }}">Editar</a>@endcan</td></tr>@empty<tr><td colspan="5"><div class="empty-state">No existen usuarios asociados.</div></td></tr>@endforelse</tbody></table></div></div><p class="mt-4 text-sm text-stone-500">No se eliminan usuarios con historial. La protección del último propietario activo permanece vigente.</p>
@endsection
