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
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-[#201a17] text-stone-100 transition-transform lg:static lg:translate-x-0">
        <div class="flex h-20 items-center gap-3 border-b border-white/10 px-6">
            <div class="grid size-10 place-items-center rounded-2xl bg-orange-500 text-xl shadow-lg shadow-orange-950/30">🍕</div>
            <div><p class="font-semibold leading-tight">{{ $activeCompany->name }}</p><p class="mt-1 text-xs text-stone-400">Gestión de pizzería</p></div>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto p-4 text-sm">
            @include('partials.nav-link', ['route' => 'dashboard', 'label' => 'Inicio', 'icon' => '⌂', 'pattern' => 'dashboard'])
            @can('create', \App\Models\Order::class)
                @include('partials.nav-link', ['route' => 'sales.create', 'label' => 'Venta', 'icon' => '＋', 'pattern' => 'sales.*'])
            @endcan
            @can('viewAny', \App\Models\RestaurantTable::class)
                @include('partials.nav-link', ['route' => 'tables.index', 'label' => 'Mesas', 'icon' => '▦', 'pattern' => 'tables.*'])
            @endcan
            @can('viewAny', \App\Models\Order::class)
                @include('partials.nav-link', ['route' => 'orders.index', 'label' => 'Pedidos', 'icon' => '☷', 'pattern' => 'orders.*'])
            @endcan
            @can('viewAny', \App\Models\KitchenDispatch::class)
                @include('partials.nav-link', ['route' => 'kitchen.index', 'label' => 'Cocina', 'icon' => '♨', 'pattern' => 'kitchen.*'])
            @endcan
            @can('viewAny', \App\Models\CashSession::class)
                @include('partials.nav-link', ['route' => 'cash.index', 'label' => 'Caja', 'icon' => 'Bs', 'pattern' => 'cash.*'])
            @endcan
            @can('viewAny', \App\Models\Product::class)
                @include('partials.nav-link', ['route' => 'products.index', 'label' => 'Productos', 'icon' => '◫', 'pattern' => 'products.*'])
                @include('partials.nav-link', ['route' => 'ingredients.index', 'label' => 'Ingredientes', 'icon' => '◇', 'pattern' => 'ingredients.*'])
            @endcan
            @can('viewAny', \App\Models\Recipe::class)
                @include('partials.nav-link', ['route' => 'recipes.index', 'label' => 'Recetas', 'icon' => '≡', 'pattern' => 'recipes.*'])
            @endcan
            @can('viewAny', \App\Models\InventoryItem::class)
                @include('partials.nav-link', ['route' => 'inventory.index', 'label' => 'Inventario', 'icon' => '▦', 'pattern' => 'inventory.*'])
            @endcan
            @can('viewAny', \App\Models\Purchase::class)
                @include('partials.nav-link', ['route' => 'purchases.index', 'label' => 'Compras', 'icon' => '↓', 'pattern' => 'purchases.*'])
            @endcan
            @can('viewAny', \App\Models\Expense::class)
                @include('partials.nav-link', ['route' => 'expenses.index', 'label' => 'Gastos', 'icon' => '−', 'pattern' => 'expenses.*'])
            @endcan
            @can('viewAny', \App\Models\Supplier::class)
                @include('partials.nav-link', ['route' => 'suppliers.index', 'label' => 'Proveedores', 'icon' => '◇', 'pattern' => 'suppliers.*'])
            @endcan
            @can('viewAny', \App\Models\ExpenseCategory::class)
                @include('partials.nav-link', ['route' => 'expense-categories.index', 'label' => 'Categorías de gasto', 'icon' => '≡', 'pattern' => 'expense-categories.*'])
            @endcan
            @can('viewAny', \App\Models\Membership::class)
                @include('partials.nav-link', ['route' => 'memberships.index', 'label' => 'Usuarios', 'icon' => '◎', 'pattern' => 'memberships.*'])
            @endcan
            @can('update', $activeCompany)
                @include('partials.nav-link', ['route' => 'settings.edit', 'label' => 'Configuración', 'icon' => '⚙', 'pattern' => 'settings.*'])
            @endcan
            @can('reports.financial')
                @include('partials.nav-link', ['route' => 'reports.index', 'label' => 'Reportes', 'icon' => '▥', 'pattern' => 'reports.*'])
            @elsecan('reports.view')
                @include('partials.nav-link', ['route' => 'reports.sales', 'label' => 'Reportes', 'icon' => '▥', 'pattern' => 'reports.*'])
            @endcan
            <div class="my-4 border-t border-white/10"></div>
        </nav>
        <div class="border-t border-white/10 p-4 text-xs text-stone-400">Operación local · Caja habilitada</div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-20 flex min-h-20 items-center justify-between border-b border-stone-200 bg-white/95 px-4 backdrop-blur sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-3">
                <button id="sidebar-toggle" type="button" class="btn-secondary px-3 lg:hidden" aria-label="Abrir menú">☰</button>
                <div class="min-w-0"><p class="truncate text-sm font-semibold">@yield('heading', 'Inicio')</p><p class="truncate text-xs text-stone-500">{{ $activeBranch?->name ?? 'Sucursal por seleccionar' }} · {{ $activeCompany->name }}</p></div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('context.branch') }}" class="hidden rounded-xl border border-stone-200 px-3 py-2 text-xs font-medium text-stone-600 hover:bg-stone-50 sm:block">Sucursal: {{ $activeBranch?->name ?? 'Elegir' }}</a>
                <div class="hidden text-right sm:block"><p class="text-sm font-medium">{{ auth()->user()->name }}</p><p class="text-xs text-stone-500">{{ \App\Support\UiFormatter::role($membership->role) }}</p></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-secondary" type="submit">Cerrar sesión</button></form>
            </div>
        </header>
        <main class="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
