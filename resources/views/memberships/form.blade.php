@extends('layouts.app')
@section('title', $membership ? 'Editar usuario' : 'Nuevo usuario')
@section('heading', 'Usuarios')
@section('content')
@php
    $selectedRole = old('role', $membership?->role->value ?? \App\Enums\MembershipRole::Waiter->value);
    $hasCustomPermissions = count(array_filter($permissionStates, fn ($state) => $state !== 'inherit')) > 0;
@endphp
<div class='mx-auto max-w-6xl'>
    <div class='page-heading'>
        <div>
            <a class='back-link' href='{{ route('memberships.index') }}'>&larr; Volver</a>
            <h1>{{ $membership ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
            <p>Configura a esta persona según el trabajo que realizará en el restaurante.</p>
        </div>
    </div>

    <form class='space-y-5' method='POST' action='{{ $membership ? route('memberships.update', $membership->id) : route('memberships.store') }}'
        data-permission-editor data-role-permissions='@json($rolePermissions)'>
        @csrf
        @if($membership) @method('PUT') @endif

        <section class='card p-5 sm:p-6'>
            <div class='mb-4'>
                <h2 class='card-title'>Datos</h2>
                <p class='card-subtitle'>Información para identificar e ingresar a la cuenta.</p>
            </div>
            <div class='grid gap-4 md:grid-cols-2'>
                <div>
                    <label class='label' for='name'>Nombre</label>
                    <input class='input' id='name' name='name' value='{{ old('name', $membership?->user->name) }}' required autofocus>
                </div>
                <div>
                    <label class='label' for='email'>Correo</label>
                    <input class='input' id='email' type='email' name='email' value='{{ old('email', $membership?->user->email) }}' @readonly($membership) required>
                    @if($membership)<p class='field-help'>El correo identifica la cuenta y no se cambia aquí.</p>@endif
                </div>
                <div>
                    <label class='label' for='password'>{{ $membership ? 'Nueva contraseña (opcional)' : 'Contraseña inicial' }}</label>
                    <input class='input' id='password' type='password' name='password' {{ $membership ? '' : 'required' }} autocomplete='new-password'>
                </div>
                <div>
                    <label class='label' for='password_confirmation'>Confirmar contraseña</label>
                    <input class='input' id='password_confirmation' type='password' name='password_confirmation' {{ $membership ? '' : 'required' }} autocomplete='new-password'>
                </div>
            </div>
        </section>

        <section class='card p-5 sm:p-6'>
            <div class='mb-4'>
                <h2 class='card-title'>Función en la empresa</h2>
                <p class='card-subtitle'>Elige la opción que mejor describe su trabajo habitual.</p>
            </div>
            <fieldset>
                <legend class='sr-only'>Función en la empresa</legend>
                <div class='grid gap-3 sm:grid-cols-2 lg:grid-cols-5'>
                    @foreach($roles as $role)
                        <label class='relative cursor-pointer rounded-2xl border p-4 transition hover:border-orange-300 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50 has-[:checked]:ring-2 has-[:checked]:ring-orange-100'
                            data-role-card='{{ $role->value }}'>
                            <input class='sr-only' type='radio' name='role' value='{{ $role->value }}' data-permission-role
                                @checked($selectedRole === $role->value) required>
                            <span class='block text-sm font-semibold text-stone-900'>{{ $role->label() }}</span>
                            <span class='mt-1 block text-xs leading-5 text-stone-600'>{{ $role->description() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <label class='mt-4 flex min-h-11 items-center gap-3 rounded-xl bg-stone-50 px-4 py-2.5'>
                <input class='size-4 rounded border-stone-300 text-orange-600 focus:ring-orange-500' type='checkbox' name='is_active' value='1'
                    @checked(old('is_active', $membership?->is_active ?? true))>
                <span><strong class='block text-sm text-stone-800'>Usuario activo</strong><small class='text-stone-500'>Puede ingresar y trabajar en esta empresa.</small></span>
            </label>
        </section>

        <section class='card p-5 sm:p-6'>
            <div class='flex flex-wrap items-start justify-between gap-3'>
                <div>
                    <div class='flex flex-wrap items-center gap-2'>
                        <h2 class='card-title'>Acceso</h2>
                        <span class='badge bg-violet-100 text-violet-700' data-custom-profile-badge @if(! $hasCustomPermissions) hidden @endif>Permisos personalizados</span>
                    </div>
                    <p class='card-subtitle'>Resumen de lo que puede hacer con su perfil actual.</p>
                </div>
                <button class='btn-secondary' type='button' data-toggle-permissions aria-expanded='false' @if($selectedRole === \App\Enums\MembershipRole::Owner->value) hidden @endif>Personalizar permisos</button>
            </div>

            <div class='mt-4 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900' data-owner-access @if($selectedRole !== \App\Enums\MembershipRole::Owner->value) hidden @endif>
                <strong class='block'>Acceso completo</strong>
                El propietario conserva acceso total a la empresa. No necesita configurar permisos individuales.
            </div>

            <div class='mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3' data-access-summary @if($selectedRole === \App\Enums\MembershipRole::Owner->value) hidden @endif>
                @foreach($permissionGroups as $group)
                    <div class='flex items-center justify-between gap-3 rounded-xl border border-stone-200 px-3 py-2.5' data-summary-module='{{ $group['key'] }}'>
                        <span class='text-sm font-medium text-stone-700'>{{ $group['label'] }}</span>
                        <span class='badge' data-summary-status>Sin acceso</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class='card p-5 sm:p-6' data-advanced-permissions hidden>
            <div class='flex flex-wrap items-start justify-between gap-3'>
                <div>
                    <h2 class='card-title'>Personalizar permisos</h2>
                    <p class='card-subtitle'>Úsalo solo si esta persona necesita una excepción a su función.</p>
                </div>
                <button class='btn-secondary' type='button' data-reset-all>Restaurar permisos del perfil</button>
            </div>

            <div class='mt-4 space-y-3'>
                @foreach($permissionGroups as $group)
                    <details class='rounded-2xl border border-stone-200 bg-white' data-permission-group='{{ $group['key'] }}'>
                        <summary class='flex cursor-pointer list-none items-center justify-between gap-4 p-4 marker:hidden'>
                            <span>
                                <strong class='block text-sm text-stone-900'>{{ $group['label'] }}</strong>
                                <small class='text-stone-500'>{{ $group['description'] }}</small>
                            </span>
                            <span class='badge' data-group-status>Sin acceso</span>
                        </summary>
                        <div class='border-t border-stone-100 p-3 sm:p-4'>
                            <div class='mb-3 flex flex-wrap gap-2'>
                                <button class='rounded-lg border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50' type='button' data-module-action='inherit'>Usar perfil en todo el módulo</button>
                                <button class='rounded-lg border border-emerald-200 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50' type='button' data-module-action='allow'>Permitir todo el módulo</button>
                                <button class='rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50' type='button' data-module-action='deny'>Bloquear todo el módulo</button>
                            </div>
                            <div class='divide-y divide-stone-100'>
                                @foreach($group['permissions'] as $permission)
                                    @php
                                        $state = old('permissions.'.$permission->value, $permissionStates[$permission->value] ?? 'inherit');
                                        $canGrant = in_array($permission->value, $actorPermissions, true);
                                    @endphp
                                    <div class='grid gap-2 py-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-center' data-permission-row
                                        data-permission='{{ $permission->value }}' data-module='{{ $group['key'] }}' data-read-only='{{ $permission->isReadOnly() ? '1' : '0' }}'>
                                        <div>
                                            <strong class='block text-sm font-medium text-stone-800'>{{ $permission->label() }}</strong>
                                            <small class='text-stone-500' data-effective-access></small>
                                        </div>
                                        <div class='inline-grid grid-cols-3 rounded-xl bg-stone-100 p-1 text-xs font-semibold' role='radiogroup' aria-label='{{ $permission->label() }}'>
                                            @foreach(['inherit' => 'Perfil', 'allow' => 'Permitir', 'deny' => 'Bloquear'] as $value => $label)
                                                <label class='cursor-pointer rounded-lg px-2.5 py-2 text-center text-stone-600 has-[:checked]:bg-white has-[:checked]:text-stone-950 has-[:checked]:shadow-sm has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-40'>
                                                    <input class='sr-only' type='radio' name='permissions[{{ $permission->value }}]' value='{{ $value }}'
                                                        @checked($state === $value) @disabled($value === 'allow' && ! $canGrant && $state !== 'allow')>
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
            <button class='mt-4 text-sm font-semibold text-orange-700 hover:underline' type='button' data-show-empty-modules hidden>Mostrar módulos sin acceso</button>
        </section>

        <p class='rounded-xl bg-stone-50 p-3 text-sm text-stone-600'>Este acceso aplica solo a {{ request()->attributes->get('company')->name }} y no cambia sus accesos en otras empresas.</p>
        <button class='btn-primary w-full'>{{ $membership ? 'Guardar cambios' : 'Crear usuario' }}</button>
    </form>
</div>

<script>
    (() => {
        const editor = document.querySelector('[data-permission-editor]');
        if (!editor) return;

        const roles = JSON.parse(editor.dataset.rolePermissions);
        const roleInputs = [...editor.querySelectorAll('[data-permission-role]')];
        const rows = [...editor.querySelectorAll('[data-permission-row]')];
        const advanced = editor.querySelector('[data-advanced-permissions]');
        const toggle = editor.querySelector('[data-toggle-permissions]');
        const ownerAccess = editor.querySelector('[data-owner-access]');
        const summary = editor.querySelector('[data-access-summary]');
        const customBadge = editor.querySelector('[data-custom-profile-badge]');
        const showEmpty = editor.querySelector('[data-show-empty-modules]');
        let showEmptyModules = false;

        const selectedRole = () => roleInputs.find((input) => input.checked)?.value || 'waiter';
        const selectedState = (row) => row.querySelector('input[type=radio]:checked')?.value || 'inherit';
        const inherited = (row) => selectedRole() === 'owner' || (roles[selectedRole()] || []).includes(row.dataset.permission);
        const effective = (row) => selectedState(row) === 'allow' || (selectedState(row) === 'inherit' && inherited(row));

        const moduleStatus = (moduleRows) => {
            if (selectedRole() === 'owner') return 'Permitido';
            if (moduleRows.some((row) => selectedState(row) !== 'inherit')) return 'Personalizado';
            const allowed = moduleRows.filter(effective);
            if (allowed.length === 0) return 'Sin acceso';
            return allowed.every((row) => row.dataset.readOnly === '1') ? 'Solo lectura' : 'Permitido';
        };

        const paintStatus = (badge, status) => {
            badge.textContent = status;
            badge.className = 'badge';
            if (status === 'Permitido') badge.classList.add('badge-success');
            if (status === 'Sin acceso') badge.classList.add('bg-stone-100', 'text-stone-600');
            if (status === 'Solo lectura') badge.classList.add('bg-sky-100', 'text-sky-700');
            if (status === 'Personalizado') badge.classList.add('bg-violet-100', 'text-violet-700');
        };

        const refresh = () => {
            const owner = selectedRole() === 'owner';
            const customized = !owner && rows.some((row) => selectedState(row) !== 'inherit');
            ownerAccess.hidden = !owner;
            summary.hidden = owner;
            toggle.hidden = owner;
            customBadge.hidden = !customized;
            if (owner) advanced.hidden = true;

            rows.forEach((row) => {
                const state = selectedState(row);
                const text = state === 'allow' ? 'Permitido para esta persona' : state === 'deny' ? 'Bloqueado para esta persona' : (inherited(row) ? 'Incluido en su perfil' : 'No incluido en su perfil');
                row.querySelector('[data-effective-access]').textContent = text;
            });

            editor.querySelectorAll('[data-permission-group]').forEach((group) => {
                const moduleRows = rows.filter((row) => row.dataset.module === group.dataset.permissionGroup);
                const status = moduleStatus(moduleRows);
                paintStatus(group.querySelector('[data-group-status]'), status);
                const hasRoleAccess = moduleRows.some(inherited);
                const hasException = moduleRows.some((row) => selectedState(row) !== 'inherit');
                group.hidden = !showEmptyModules && !hasRoleAccess && !hasException;
            });

            editor.querySelectorAll('[data-summary-module]').forEach((item) => {
                const moduleRows = rows.filter((row) => row.dataset.module === item.dataset.summaryModule);
                paintStatus(item.querySelector('[data-summary-status]'), moduleStatus(moduleRows));
            });

            showEmpty.hidden = owner || showEmptyModules || ![...editor.querySelectorAll('[data-permission-group]')].some((group) => group.hidden);
        };

        toggle.addEventListener('click', () => {
            advanced.hidden = !advanced.hidden;
            toggle.setAttribute('aria-expanded', advanced.hidden ? 'false' : 'true');
            toggle.textContent = advanced.hidden ? 'Personalizar permisos' : 'Ocultar personalización';
            if (!advanced.hidden) advanced.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        roleInputs.forEach((input) => input.addEventListener('change', refresh));
        rows.forEach((row) => row.querySelectorAll('input[type=radio]').forEach((input) => input.addEventListener('change', refresh)));

        editor.querySelector('[data-reset-all]').addEventListener('click', () => {
            if (!confirm('¿Restaurar todos los permisos definidos por esta función? Se quitarán las personalizaciones.')) return;
            rows.forEach((row) => { row.querySelector('input[value=inherit]').checked = true; });
            refresh();
        });

        editor.querySelectorAll('[data-module-action]').forEach((button) => button.addEventListener('click', () => {
            const group = button.closest('[data-permission-group]');
            const state = button.dataset.moduleAction;
            const label = state === 'allow' ? 'permitir' : state === 'deny' ? 'bloquear' : 'restaurar según el perfil';
            if (!confirm(`¿Deseas ${label} todos los permisos de ${group.querySelector('strong').textContent}?`)) return;
            rows.filter((row) => row.dataset.module === group.dataset.permissionGroup).forEach((row) => {
                const input = row.querySelector(`input[value=${state}]`);
                if (input && !input.disabled) input.checked = true;
            });
            refresh();
        }));

        showEmpty.addEventListener('click', () => { showEmptyModules = true; refresh(); });
        refresh();
    })();
</script>
@endsection
