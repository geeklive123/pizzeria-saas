@php
    $isCashier = $membership->role === \App\Enums\MembershipRole::Cashier;
    $canViewReports = \Illuminate\Support\Facades\Gate::allows('reports.financial')
        || \Illuminate\Support\Facades\Gate::allows('reports.view');
    $reportRoute = \Illuminate\Support\Facades\Gate::allows('reports.financial') ? 'reports.index' : 'reports.sales';
    $navigationGroups = [
        ['key' => 'principal', 'label' => 'Principal', 'items' => [
            ['route' => 'dashboard', 'label' => 'Inicio', 'icon' => '⌂', 'pattern' => 'dashboard', 'visible' => ! $isCashier],
        ]],
        ['key' => 'sales', 'label' => 'Ventas y atención', 'items' => [
            ['route' => 'sales.create', 'label' => 'Nueva venta', 'icon' => '＋', 'pattern' => 'sales.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('create', \App\Models\Order::class)],
            ['route' => 'orders.index', 'label' => 'Pedidos', 'icon' => '☷', 'pattern' => 'orders.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Order::class)],
            ['route' => 'tables.index', 'label' => 'Mesas', 'icon' => '▦', 'pattern' => 'tables.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\RestaurantTable::class)],
            ['route' => 'menu-availability.index', 'label' => 'Menú', 'icon' => '◒', 'pattern' => 'menu-availability.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('menu-availability.view')],
            ['route' => 'cash.index', 'label' => 'Caja', 'icon' => 'Bs', 'pattern' => 'cash.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\CashSession::class)],
        ]],
        ['key' => 'operations', 'label' => 'Operación', 'items' => [
            ['route' => 'kitchen.index', 'label' => 'Cocina', 'icon' => '♨', 'pattern' => 'kitchen.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\KitchenDispatch::class)],
            ['route' => 'preparations.index', 'label' => 'Preparaciones', 'icon' => 'P', 'pattern' => 'preparations.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Preparation::class)],
        ]],
        ['key' => 'catalog', 'label' => 'Productos y recetas', 'items' => [
            ['route' => 'products.index', 'label' => 'Productos', 'icon' => '◫', 'pattern' => 'products.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Product::class)],
            ['route' => 'categories.index', 'label' => 'Categorías', 'icon' => '≡', 'pattern' => 'categories.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Product::class)],
            ['route' => 'promotions.index', 'label' => 'Promociones', 'icon' => '%', 'pattern' => 'promotions.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Product::class)],
            ['route' => 'ingredients.index', 'label' => 'Ingredientes', 'icon' => '◇', 'pattern' => 'ingredients.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Product::class)],
            ['route' => 'recipes.index', 'label' => 'Recetas', 'icon' => '▤', 'pattern' => 'recipes.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Recipe::class)],
            ['route' => 'units.index', 'label' => 'Unidades', 'icon' => '↔', 'pattern' => 'units.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Product::class)],
        ]],
        ['key' => 'inventory', 'label' => 'Inventario y compras', 'items' => [
            ['route' => 'inventory.index', 'label' => 'Inventario', 'icon' => '▦', 'pattern' => 'inventory.*', 'visible' => \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\InventoryItem::class)],
            ['route' => 'purchases.index', 'label' => 'Compras', 'icon' => '↓', 'pattern' => 'purchases.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Purchase::class)],
            ['route' => 'suppliers.index', 'label' => 'Proveedores', 'icon' => '⇥', 'pattern' => 'suppliers.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Supplier::class)],
        ]],
        ['key' => 'administration', 'label' => 'Administración', 'items' => [
            ['route' => 'expenses.index', 'label' => 'Gastos', 'icon' => '−', 'pattern' => 'expenses.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Expense::class)],
            ['route' => 'memberships.index', 'label' => 'Usuarios', 'icon' => '◎', 'pattern' => 'memberships.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Membership::class)],
            ['route' => 'settings.edit', 'label' => 'Configuración', 'icon' => '⚙', 'pattern' => 'settings.*', 'visible' => ! $isCashier && \Illuminate\Support\Facades\Gate::allows('update', $activeCompany)],
            ['route' => $reportRoute, 'label' => 'Reportes', 'icon' => '▥', 'pattern' => 'reports.*', 'visible' => $canViewReports],
        ]],
    ];
@endphp

<div class="space-y-2" data-sidebar-navigation data-navigation-scope="{{ $activeCompany->ulid }}">
@foreach($navigationGroups as $group)
    @php
        $visibleItems = collect($group['items'])->where('visible', true)->values();
        $groupIsActive = $visibleItems->contains(fn (array $item): bool => request()->routeIs($item['pattern']));
    @endphp
    @if($visibleItems->isNotEmpty())
        <section class="sidebar-group" data-nav-group data-nav-key="{{ $group['key'] }}" data-active="{{ $groupIsActive ? 'true' : 'false' }}" data-expanded="{{ $groupIsActive ? 'true' : 'false' }}">
            <button class="sidebar-group-toggle" type="button" aria-expanded="{{ $groupIsActive ? 'true' : 'false' }}" aria-controls="sidebar-group-{{ $group['key'] }}" data-nav-group-toggle>
                <span>{{ $group['label'] }}</span><span class="sidebar-group-chevron" aria-hidden="true">›</span>
            </button>
            <div class="sidebar-group-panel" id="sidebar-group-{{ $group['key'] }}" data-nav-group-panel aria-hidden="{{ $groupIsActive ? 'false' : 'true' }}" @if(! $groupIsActive) inert @endif>
                <div class="min-h-0 space-y-1 overflow-hidden pb-1">
                    @foreach($visibleItems as $item)
                        @include('partials.nav-link', $item)
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endforeach
</div>
