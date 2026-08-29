<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\KitchenDispatch;
use App\Models\Membership;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RestaurantTable;
use App\Models\Supplier;
use App\Models\Unit;
use App\Policies\BranchPolicy;
use App\Policies\CashRegisterPolicy;
use App\Policies\CashSessionPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ExpenseCategoryPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InventoryPolicy;
use App\Policies\KitchenDispatchPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\RecipePolicy;
use App\Policies\ReportPolicy;
use App\Policies\RestaurantTablePolicy;
use App\Policies\SupplierPolicy;
use App\Printing\Contracts\ThermalPrinterTransport;
use App\Printing\FileThermalPrinterTransport;
use App\Printing\WindowsRawPrinterTransport;
use App\Services\PrintAgentStatusService;
use App\Support\BranchContext;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewContract;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CompanyContext::class);
        $this->app->scoped(BranchContext::class);
        $this->app->bind(ThermalPrinterTransport::class, function ($app): ThermalPrinterTransport {
            return match ((string) config('thermal-printing.agent.transport')) {
                'file' => $app->make(FileThermalPrinterTransport::class),
                'windows_raw' => $app->make(WindowsRawPrinterTransport::class),
                default => throw new InvalidArgumentException('PRINT_AGENT_TRANSPORT debe ser file o windows_raw.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
        Gate::policy(Unit::class, CatalogPolicy::class);
        Gate::policy(Category::class, CatalogPolicy::class);
        Gate::policy(Product::class, CatalogPolicy::class);
        Gate::policy(ProductVariant::class, CatalogPolicy::class);
        Gate::policy(Promotion::class, CatalogPolicy::class);
        Gate::policy(Ingredient::class, CatalogPolicy::class);
        Gate::policy(ModifierOption::class, CatalogPolicy::class);
        Gate::policy(Recipe::class, RecipePolicy::class);
        Gate::policy(RecipeItem::class, RecipePolicy::class);
        Gate::policy(InventoryStock::class, InventoryPolicy::class);
        Gate::policy(InventoryItem::class, InventoryPolicy::class);
        Gate::policy(InventoryBatch::class, InventoryPolicy::class);
        Gate::policy(InventoryMovement::class, InventoryPolicy::class);
        Gate::policy(Purchase::class, PurchasePolicy::class);
        Gate::policy(PurchaseItem::class, PurchasePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(RestaurantTable::class, RestaurantTablePolicy::class);
        Gate::policy(KitchenDispatch::class, KitchenDispatchPolicy::class);
        Gate::policy(CashRegister::class, CashRegisterPolicy::class);
        Gate::policy(CashSession::class, CashSessionPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::define('reports.view', [ReportPolicy::class, 'view']);
        Gate::define('reports.financial', [ReportPolicy::class, 'financial']);
        Gate::define('reports.export', [ReportPolicy::class, 'export']);

        View::composer('layouts.app', function (ViewContract $view): void {
            $company = request()->attributes->get('company');
            $branch = request()->attributes->get('branch');
            $online = false;

            if ($company instanceof Company && $branch instanceof Branch) {
                $agents = app(PrintAgentStatusService::class);
                $online = $agents->isOnline($agents->current($company, $branch));
            }

            $view->with('globalPrintAgentOnline', $online);
        });
    }
}
