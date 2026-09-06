<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Pizzería') · {{ request()->attributes->get('company')->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-stone-50 text-stone-900 antialiased">
@php
    $activeCompany = request()->attributes->get('company');
    $activeBranch = request()->attributes->get('branch');
    $membership = app(\App\Support\CompanyContext::class)->membership();
@endphp
<div class="min-h-screen lg:flex">
    <div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-stone-950/40 lg:hidden"></div>
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-64 max-w-[calc(100vw-2rem)] -translate-x-full flex-col bg-[#141b2a] text-stone-100 shadow-2xl shadow-slate-950/20 transition-transform lg:static lg:translate-x-0" tabindex="-1" aria-label="Menú principal">
        <div class="flex h-20 items-center gap-3 border-b border-white/10 px-6">
            <div class="grid size-10 place-items-center rounded-2xl bg-orange-500 text-xl shadow-lg shadow-orange-950/30">🍕</div>
            <div><p class="font-semibold leading-tight">{{ $activeCompany->name }}</p><p class="mt-1 text-xs text-stone-400">Gestión de pizzería</p></div>
        </div>
        <nav class="flex-1 overflow-x-hidden overflow-y-auto px-3 py-4 text-sm" aria-label="Navegación principal">
            @include('partials.sidebar-navigation')
        </nav>
        <div class="space-y-2 border-t border-white/10 p-4 text-xs text-stone-400">
            <div class="flex items-center gap-2" data-print-agent-status>
                <span class="size-2 rounded-full {{ $globalPrintAgentOnline ? 'bg-emerald-400' : 'bg-red-400' }}" aria-hidden="true"></span>
                <span>Impresora</span>
                <strong class="font-medium {{ $globalPrintAgentOnline ? 'text-emerald-300' : 'text-red-300' }}">{{ $globalPrintAgentOnline ? 'En línea' : 'Fuera de línea' }}</strong>
            </div>
            <p>Operación local · Caja habilitada</p>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-20 flex min-h-20 items-center justify-between border-b border-stone-200 bg-white/95 px-4 backdrop-blur sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-3">
                <button id="sidebar-toggle" type="button" class="btn-secondary px-3 lg:hidden" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false">☰</button>
                <div class="min-w-0"><p class="truncate text-lg font-semibold tracking-tight">@yield('heading', 'Inicio')</p><p class="truncate text-xs text-slate-500">@yield('header-subtitle', ($activeBranch?->name ?? 'Sucursal por seleccionar').' · '.$activeCompany->name)</p></div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('context.branch') }}" class="hidden rounded-xl border border-stone-200 px-3 py-2 text-xs font-medium text-stone-600 hover:bg-stone-50 sm:block">Sucursal: {{ $activeBranch?->name ?? 'Elegir' }}</a>
                <div class="hidden items-center gap-2 rounded-xl border border-stone-200 px-3 py-2 text-xs text-stone-600 xl:flex"><span aria-hidden="true">◷</span><time datetime="{{ now(config('reports.timezone', 'America/La_Paz'))->toIso8601String() }}">{{ now(config('reports.timezone', 'America/La_Paz'))->format('d/m/Y H:i') }}</time></div>
                <div class="hidden text-right md:block"><p class="text-sm font-medium">{{ auth()->user()->name }}</p><p class="text-xs text-stone-500">{{ \App\Support\UiFormatter::role($membership->role) }}</p></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-secondary" type="submit">Cerrar sesión</button></form>
            </div>
        </header>
        <main class="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8 @yield('main-class')">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
