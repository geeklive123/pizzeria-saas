<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RestaurantTableController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\ToppingController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('auth.login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/context/companies', [ContextController::class, 'companies'])->name('context.companies');
    Route::post('/context/companies', [ContextController::class, 'selectCompany'])->name('context.company.select');

    Route::middleware('company.context')->group(function (): void {
        Route::get('/context/branches', [ContextController::class, 'branches'])->name('context.branch');
        Route::post('/context/branches', [ContextController::class, 'selectBranch'])->name('context.branch.select');

        Route::middleware('branch.context')->group(function (): void {
            Route::get('/dashboard', DashboardController::class)->name('dashboard');

            Route::get('/sales/create', [SaleController::class, 'create'])->name('sales.create');
            Route::get('/tables', [RestaurantTableController::class, 'index'])->name('tables.index');
            Route::get('/tables/create', [RestaurantTableController::class, 'create'])->name('tables.create');
            Route::post('/tables', [RestaurantTableController::class, 'store'])->name('tables.store');
            Route::get('/tables/{table}/edit', [RestaurantTableController::class, 'edit'])->name('tables.edit');
            Route::put('/tables/{table}', [RestaurantTableController::class, 'update'])->name('tables.update');
            Route::post('/tables/{table}/open', [RestaurantTableController::class, 'open'])->name('tables.open');
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/takeaway/create', [OrderController::class, 'createTakeaway'])->name('orders.takeaway.create');
            Route::post('/orders/takeaway', [OrderController::class, 'storeTakeaway'])->name('orders.takeaway.store');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::put('/orders/{order}/customer', [OrderController::class, 'updateCustomer'])->name('orders.customer.update');
            Route::post('/orders/{order}/items', [OrderController::class, 'addItem'])->name('orders.items.store');
            Route::post('/orders/{order}/promotions', [OrderController::class, 'addPromotion'])->name('orders.promotions.store');
            Route::post('/orders/{order}/dispatch', [OrderController::class, 'dispatch'])->name('orders.dispatch');
            Route::post('/orders/{order}/finalize-table', [OrderController::class, 'finalizeTable'])->name('orders.finalize-table');
            Route::post('/orders/{order}/kitchen-dispatches/{dispatch}/print', [OrderController::class, 'printKitchen'])->name('orders.kitchen.print');
            Route::put('/orders/{order}/items/{item}', [OrderController::class, 'updateItem'])->name('orders.items.update');
            Route::post('/orders/{order}/items/{item}/served', [OrderController::class, 'serveItem'])->name('orders.items.served');
            Route::post('/orders/{order}/items/{item}/cancel', [OrderController::class, 'cancelItem'])->name('orders.items.cancel');
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
            Route::get('/orders/{order}/checkout', [CheckoutController::class, 'show'])->name('orders.checkout');
            Route::post('/orders/{order}/request-payment', [CheckoutController::class, 'requestPayment'])->name('orders.request-payment');
            Route::post('/orders/{order}/payments', [CheckoutController::class, 'store'])->name('orders.payments.store');
            Route::post('/orders/{order}/ticket/print', [CheckoutController::class, 'printTicket'])->name('orders.ticket.print');
            Route::post('/payments/{payment}/reverse', [CheckoutController::class, 'reverse'])->name('payments.reverse');

            Route::get('/cash', [CashController::class, 'index'])->name('cash.index');
            Route::get('/cash/open', [CashController::class, 'openForm'])->name('cash.open.form');
            Route::post('/cash/open', [CashController::class, 'open'])->name('cash.open');
            Route::get('/cash/current', [CashController::class, 'index'])->name('cash.current');
            Route::post('/cash/current/movements', [CashController::class, 'movement'])->name('cash.movements.store');
            Route::post('/cash/sessions/{session}/withdrawals', [CashController::class, 'withdrawal'])->name('cash.withdrawals.store');
            Route::post('/cash/current/close', [CashController::class, 'close'])->name('cash.close');
            Route::resource('cash-registers', CashRegisterController::class)->except(['show', 'destroy']);
            Route::post('/cash-registers/{cash_register}/toggle', [CashRegisterController::class, 'toggle'])->name('cash-registers.toggle');

            Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
            Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::post('/expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->name('expenses.reverse');
            Route::resource('expense-categories', ExpenseCategoryController::class)->except(['show', 'destroy']);
            Route::post('/expense-categories/{expense_category}/toggle', [ExpenseCategoryController::class, 'toggle'])->name('expense-categories.toggle');
            Route::resource('suppliers', SupplierController::class)->except(['show', 'destroy']);
            Route::post('/suppliers/{supplier}/toggle', [SupplierController::class, 'toggle'])->name('suppliers.toggle');

            Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
            Route::get('/reports/products', [ReportController::class, 'products'])->name('reports.products');
            Route::get('/reports/cash', [ReportController::class, 'cash'])->name('reports.cash');
            Route::get('/reports/cash/{session}', [ReportController::class, 'cashDetail'])->name('reports.cash.show');
            Route::get('/reports/expenses', [ReportController::class, 'expenses'])->name('reports.expenses');
            Route::get('/reports/purchases', [ReportController::class, 'purchases'])->name('reports.purchases');
            Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
            Route::get('/reports/waste', [ReportController::class, 'waste'])->name('reports.waste');
            Route::get('/reports/profitability', [ReportController::class, 'profitability'])->name('reports.profitability');
            Route::get('/reports/export/{report}', [ReportController::class, 'export'])->name('reports.export');

            Route::get('/kitchen', [KitchenController::class, 'index'])->name('kitchen.index');
            Route::post('/kitchen/items/{item}/start', [KitchenController::class, 'start'])->name('kitchen.items.start');
            Route::post('/kitchen/items/{item}/ready', [KitchenController::class, 'ready'])->name('kitchen.items.ready');

            Route::resource('products', ProductController::class)->except(['show', 'destroy']);
            Route::resource('promotions', PromotionController::class)->except(['show', 'destroy']);
            Route::post('/promotions/{promotion}/toggle', [PromotionController::class, 'toggle'])->name('promotions.toggle');
            Route::resource('toppings', ToppingController::class)->except(['show', 'destroy']);
            Route::post('/toppings/{topping}/toggle', [ToppingController::class, 'toggle'])->name('toppings.toggle');
            Route::resource('categories', CategoryController::class)->except(['show', 'destroy']);
            Route::post('/categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('categories.toggle');
            Route::resource('units', UnitController::class)->except(['show', 'destroy']);
            Route::post('/units/initialize', [UnitController::class, 'initialize'])->name('units.initialize');
            Route::post('/units/{unit}/toggle', [UnitController::class, 'toggle'])->name('units.toggle');
            Route::resource('ingredients', IngredientController::class)->except(['show', 'destroy']);
            Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::get('/recipes/create', [RecipeController::class, 'create'])->name('recipes.create');
            Route::get('/recipes/{variant}/edit', [RecipeController::class, 'edit'])->name('recipes.edit');
            Route::put('/recipes/{variant}', [RecipeController::class, 'update'])->name('recipes.update');

            Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('/inventory/movements', [InventoryMovementController::class, 'index'])->name('inventory.movements');
            Route::get('/inventory/{item}', [InventoryController::class, 'show'])->name('inventory.show');
            Route::post('/inventory/{item}/operations', [InventoryController::class, 'operate'])->name('inventory.operate');
            Route::put('/inventory/{item}/minimum', [InventoryController::class, 'updateMinimum'])->name('inventory.minimum.update');
            Route::post('/inventory/{item}/batches/{batch}/discard-expired', [InventoryController::class, 'discardExpired'])->name('inventory.batches.discard-expired');

            Route::resource('purchases', PurchaseController::class)->except(['destroy']);
            Route::post('/purchases/{purchase}/post', [PurchaseController::class, 'post'])->name('purchases.post');
            Route::post('/purchases/{purchase}/reverse', [PurchaseController::class, 'reverse'])->name('purchases.reverse');

            Route::get('/users', [MembershipController::class, 'index'])->name('memberships.index');
            Route::get('/users/create', [MembershipController::class, 'create'])->name('memberships.create');
            Route::post('/users', [MembershipController::class, 'store'])->name('memberships.store');
            Route::get('/users/{membership}/edit', [MembershipController::class, 'edit'])->name('memberships.edit');
            Route::put('/users/{membership}', [MembershipController::class, 'update'])->name('memberships.update');
            Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::put('/settings/charge-mode', [SettingsController::class, 'updateChargeMode'])->name('settings.charge-mode');
            Route::post('/settings/printers/{purpose}/test', [SettingsController::class, 'printTest'])->name('settings.printers.test');
        });
    });
});
