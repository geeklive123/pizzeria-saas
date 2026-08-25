@extends('layouts.app')
@section('title', $membership ? 'Editar usuario' : 'Nuevo usuario')
@section('heading', 'Usuarios')
@section('content')
<div class='mx-auto max-w-5xl'>
    <div class='page-heading'>
        <div>
            <a class='back-link' href='{{ route('memberships.index') }}'>&larr; Volver</a>
            <h1>{{ $membership ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
            <p>{{ $membership ? 'Actualiza sus datos, rol y excepciones dentro de la empresa activa.' : 'Crea una cuenta y define su acceso a la empresa activa.' }}</p>
        </div>
    </div>

    <form class='space-y-6' method='POST' action='{{ $membership ? route('memberships.update', $membership->id) : route('memberships.store') }}'>
        @csrf
        @if($membership) @method('PUT') @endif

        <section class='card space-y-5 p-6'>
            <h2 class='card-title'>Datos del usuario</h2>
            <div><label class='label' for='name'>Nombre</label><input class='input' id='name' name='name' value='{{ old('name', $membership?->user->name) }}' required autofocus></div>
            <div><label class='label' for='email'>Correo</label><input class='input' id='email' type='email' name='email' value='{{ old('email', $membership?->user->email) }}' @readonly($membership) required><p class='mt-1 text-xs text-stone-500'>{{ $membership ? 'El correo identifica la cuenta y no se cambia desde esta pantalla.' : 'Si el correo ya existe, se conserva su cuenta y se agrega solo esta membresía.' }}</p></div>
            <div class='grid gap-4 sm:grid-cols-2'>
                <div><label class='label' for='password'>{{ $membership ? 'Nueva contraseña (opcional)' : 'Contraseña inicial' }}</label><input class='input' id='password' type='password' name='password' {{ $membership ? '' : 'required' }} autocomplete='new-password'></div>
                <div><label class='label' for='password_confirmation'>Confirmar contraseña</label><input class='input' id='password_confirmation' type='password' name='password_confirmation' {{ $membership ? '' : 'required' }} autocomplete='new-password'></div>
            </div>
            <div><label class='label' for='role'>Rol base</label><select class='input' id='role' name='role' required data-permission-role>@foreach($roles as $role)<option value='{{ $role->value }}' @selected(old('role', $membership?->role->value ?? \App\Enums\MembershipRole::Waiter->value) === $role->value)>{{ \App\Support\UiFormatter::role($role) }}</option>@endforeach</select><p class='mt-1 text-xs text-stone-500'>El rol aporta los permisos predeterminados. Las excepciones de abajo solo aplican a este usuario en esta empresa.</p></div>
            <label class='flex items-center gap-2'><input type='checkbox' name='is_active' value='1' @checked(old('is_active', $membership?->is_active ?? true))><span>Usuario activo en esta empresa</span></label>
        </section>

        <section class='card p-6' data-permission-editor data-role-permissions='@json($rolePermissions)'>
            <div class='flex flex-wrap items-start justify-between gap-3'>
                <div><h2 class='card-title'>Permisos por módulo</h2><p class='card-subtitle'>Heredar conserva la regla del rol. Permitir o Denegar crea una excepción auditable para esta membresía.</p></div>
                <div class='flex gap-2 text-xs'><span class='badge badge-success'>Heredado: permitido</span><span class='badge'>Heredado: no incluido</span></div>
            </div>
            <div class='mt-5 space-y-5'>
                @foreach($permissionGroups as $group)
                    <fieldset class='rounded-2xl border border-stone-200 p-4'>
                        <legend class='px-2 font-semibold'>{{ $group['label'] }}</legend>
                        <div class='grid gap-3 lg:grid-cols-2'>
                            @foreach($group['permissions'] as $permission)
                                @php
                                    $state = old('permissions.'.$permission->value, $permissionStates[$permission->value] ?? 'inherit');
                                    $canGrant = in_array($permission->value, $actorPermissions, true);
                                @endphp
                                <label class='rounded-xl bg-stone-50 p-3'>
                                    <span class='flex items-start justify-between gap-3'>
                                        <span><strong class='block text-sm'>{{ $permission->label() }}</strong><small class='text-stone-500'>{{ $permission->value }}</small></span>
                                        <span class='badge' data-inherited-permission='{{ $permission->value }}'></span>
                                    </span>
                                    <select class='input mt-3' name='permissions[{{ $permission->value }}]'>
                                        <option value='inherit' @selected($state === 'inherit')>Heredar del rol</option>
                                        <option value='allow' @selected($state === 'allow') @disabled(! $canGrant)>Permitir explícitamente</option>
                                        <option value='deny' @selected($state === 'deny')>Denegar explícitamente</option>
                                    </select>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <p class='mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900' data-owner-permission-note hidden>El rol Propietario conserva acceso total por seguridad. Sus excepciones se descartan.</p>
        </section>

        <script>
            (() => {
                const editor = document.querySelector('[data-permission-editor]');
                const role = document.querySelector('[data-permission-role]');
                if (! editor || ! role) return;
                const permissions = JSON.parse(editor.dataset.rolePermissions);
                const refresh = () => {
                    const inherited = new Set(permissions[role.value] || []);
                    editor.querySelectorAll('[data-inherited-permission]').forEach((badge) => {
                        const allowed = role.value === 'owner' || inherited.has(badge.dataset.inheritedPermission);
                        badge.textContent = allowed ? 'Heredado: permitido' : 'Heredado: no incluido';
                        badge.classList.toggle('badge-success', allowed);
                    });
                    editor.querySelector('[data-owner-permission-note]').hidden = role.value !== 'owner';
                };
                role.addEventListener('change', refresh);
                refresh();
            })();
        </script>

        <p class='rounded-xl bg-stone-50 p-3 text-sm text-stone-600'>La asignación aplica a {{ request()->attributes->get('company')->name }}. La membresía no reemplaza accesos del mismo usuario en otras empresas.</p>
        <button class='btn-primary w-full'>{{ $membership ? 'Guardar cambios' : 'Crear usuario' }}</button>
    </form>
</div>
@endsection
