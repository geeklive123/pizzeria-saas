@extends('layouts.app')
@section('title', 'Registro de accesos')
@section('heading', 'Registro de accesos')
@section('content')
<div class="page-heading">
    <div><h1>Registro de accesos</h1><p>Sesiones de usuarios de caja y cocina · {{ $range->label }}</p></div>
</div>
<form class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-8" method="GET">
    <label><span class="label">Período</span><select class="input" name="preset"><option value="today" @selected($range->preset === 'today')>Hoy</option><option value="yesterday" @selected($range->preset === 'yesterday')>Ayer</option><option value="week" @selected($range->preset === 'week')>Semana</option><option value="custom" @selected($range->preset === 'custom')>Rango</option></select></label>
    <label><span class="label">Desde</span><input class="input" type="date" name="date_from" value="{{ $range->from->toDateString() }}"></label>
    <label><span class="label">Hasta</span><input class="input" type="date" name="date_to" value="{{ $range->to->toDateString() }}"></label>
    <label><span class="label">Usuario</span><select class="input" name="user_id"><option value="">Todos</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></label>
    <label><span class="label">Rol</span><select class="input" name="role"><option value="">Todos</option><option value="cashier" @selected(($filters['role'] ?? '') === 'cashier')>Cajero</option><option value="kitchen" @selected(($filters['role'] ?? '') === 'kitchen')>Cocina</option></select></label>
    <label><span class="label">Sucursal</span><select class="input" name="branch_id"><option value="">Todas</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
    <label><span class="label">Estado</span><select class="input" name="status"><option value="">Todos</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activa</option><option value="closed" @selected(($filters['status'] ?? '') === 'closed')>Cerrada</option></select></label>
    <button class="btn-primary self-end">Aplicar filtros</button>
</form>
<section class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Inicio</th><th>Última actividad</th><th>Cierre</th><th>Duración</th><th>Motivo de cierre</th><th>Estado</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td>{{ $log->user->name }}</td><td>{{ $log->role->label() }}</td><td>{{ $log->branch?->name ?? '—' }}</td>
                <td>{{ \App\Support\UiFormatter::date($log->logged_in_at, true) }}</td><td>{{ \App\Support\UiFormatter::date($log->last_activity_at, true) }}</td><td>{{ \App\Support\UiFormatter::date($log->logged_out_at, true) }}</td>
                <td>{{ intdiv($log->duration_minutes, 60) }} h {{ $log->duration_minutes % 60 }} min</td><td>{{ $log->logout_reason?->label() ?? '—' }}</td>
                <td><span class="badge {{ $log->logged_out_at ? 'badge-neutral' : 'badge-success' }}">{{ $log->logged_out_at ? 'Cerrada' : 'Activa' }}</span></td>
            </tr>
        @empty
            <tr><td colspan="9"><div class="empty-state">No hay accesos para los filtros seleccionados.</div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="p-4">{{ $logs->links() }}</div>
</section>
@endsection
