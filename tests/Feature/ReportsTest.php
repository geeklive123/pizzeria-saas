<?php

namespace Tests\Feature;

use App\Actions\CloseCashSessionAction;
use App\Actions\OpenCashSessionAction;
use App\Data\ReportDateRange;
use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSection;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashReportService;
use App\Services\ExpenseReportService;
use App\Services\InventoryReportService;
use App\Services\ProfitabilityReportService;
use App\Services\PurchaseReportService;
use App\Services\ReportDateRangeService;
use App\Services\SalesReportService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_today_range_partial_orders_and_branch_scope_are_correct(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        $today = CarbonImmutable::now(config('reports.timezone'))->setTime(12, 0)->utc();
        $yesterday = $today->subDay();
        $this->paidOrder($company, $branch, $owner, $session, '100.00', $today);
        $this->paidOrder($company, $branch, $owner, $session, '50.00', $yesterday);
        Order::factory()->for($branch)->create(['company_id' => $company->id, 'status' => OrderStatus::ReadyForPayment, 'total' => '80.00', 'closed_at' => null, 'created_by' => $owner->id]);
        $otherBranch = Branch::factory()->for($company)->create();
        $this->paidOrder($company, $otherBranch, $owner, $session, '999.00', $today, createPayment: false);

        $todayData = app(SalesReportService::class)->data($company, $branch, $this->range('today'));
        $customData = app(SalesReportService::class)->data($company, $branch, $this->range('custom', $yesterday->setTimezone(config('reports.timezone'))->toDateString(), $today->setTimezone(config('reports.timezone'))->toDateString()));

        $this->assertSame('100.00', $todayData['sales_total']);
        $this->assertSame(1, $todayData['orders_paid']);
        $this->assertSame('100.00', $todayData['average_ticket']);
        $this->assertSame('150.00', $customData['sales_total']);
        $this->assertSame(2, $customData['orders_paid']);
    }

    public function test_reversed_payments_are_excluded_and_mixed_payment_is_split_without_a_mixed_method(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        $order = $this->paidOrder($company, $branch, $owner, $session, '100.00', now(), createPayment: false);
        $this->payment($order, $session, $owner, PaymentMethod::Cash, '60.00', PaymentStatus::Completed, 'cash');
        $this->payment($order, $session, $owner, PaymentMethod::Qr, '40.00', PaymentStatus::Completed, 'qr');
        $this->payment($order, $session, $owner, PaymentMethod::Qr, '20.00', PaymentStatus::Reversed, 'reversed');

        $data = app(SalesReportService::class)->data($company, $branch, $this->range());
        $cash = $data['payments']->firstWhere('method', PaymentMethod::Cash->value);
        $qr = $data['payments']->firstWhere('method', PaymentMethod::Qr->value);

        $this->assertSame('60.00', $cash['amount']);
        $this->assertSame('40.00', $qr['amount']);
        $this->assertSame('60.00', $cash['percentage']);
        $this->assertSame('40.00', $qr['percentage']);
        $this->assertNull($data['payments']->firstWhere('method', 'mixed'));
    }

    public function test_product_variant_and_fused_pizza_rankings_do_not_duplicate_complete_units(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        $product = Product::factory()->for($company)->create(['name' => 'Pizza', 'type' => ProductType::Pizza]);
        $base = ProductVariant::factory()->for($product)->create(['company_id' => $company->id, 'name' => 'Familiar']);
        $pepperoni = ProductVariant::factory()->for($product)->create(['company_id' => $company->id, 'name' => 'Pepperoni']);
        $hawaiian = ProductVariant::factory()->for($product)->create(['company_id' => $company->id, 'name' => 'Hawaiana']);
        $order = $this->paidOrder($company, $branch, $owner, $session, '90.00', now(), $base);
        $item = $order->items()->firstOrFail();
        foreach ([[$pepperoni, 1], [$hawaiian, 2]] as [$variant, $position]) {
            OrderItemSection::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'order_item_id' => $item->id, 'product_variant_id' => $variant->id, 'fraction_numerator' => 1, 'fraction_denominator' => 2, 'position' => $position, 'unit_price_snapshot' => '90.00', 'product_name_snapshot' => 'Pizza', 'variant_name_snapshot' => $variant->name]);
        }

        $data = app(SalesReportService::class)->data($company, $branch, $this->range());
        $this->assertSame('1.000', $data['products']->first()['quantity']);
        $this->assertSame('1.000', $data['variants']->first()['quantity']);
        $this->assertSame('0.500', $data['pizza_analysis']['flavors']->firstWhere('name', 'Pizza Pepperoni')['quantity']);
        $this->assertSame('0.500', $data['pizza_analysis']['flavors']->firstWhere('name', 'Pizza Hawaiana')['quantity']);
    }

    public function test_expenses_are_grouped_and_kept_separate_from_inventory_purchases(): void
    {
        [$company, $branch, $owner] = $this->context();
        $category = ExpenseCategory::factory()->for($company)->create(['name' => 'Servicios']);
        Expense::factory()->create($this->expenseAttributes($company, $branch, $owner, $category, '30.00'));
        Expense::factory()->create(array_merge($this->expenseAttributes($company, $branch, $owner, $category, '70.00'), ['status' => ExpenseStatus::Reversed]));
        $purchase = Purchase::factory()->for($branch)->create(['company_id' => $company->id, 'status' => PurchaseStatus::Posted, 'purchased_at' => now(), 'created_by' => $owner->id]);
        $item = $this->inventoryItem($company);
        PurchaseItem::withoutEvents(fn () => PurchaseItem::query()->create(['company_id' => $company->id, 'purchase_id' => $purchase->id, 'inventory_item_id' => $item->id, 'quantity' => '5.000', 'input_unit_id' => $item->unit_id, 'base_quantity' => '5.000', 'unit_cost' => '20.000000', 'total_cost' => '100.000000']));

        $expenses = app(ExpenseReportService::class)->data($company, $branch, $this->range());
        $purchases = app(PurchaseReportService::class)->data($company, $branch, $this->range());

        $this->assertSame('30.00', $expenses['total']);
        $this->assertSame('30.00', $expenses['by_category']->first()['amount']);
        $this->assertSame('100.00', $purchases['total']);
    }

    public function test_inventory_waste_consumption_and_value_use_real_ledger_and_current_stock(): void
    {
        [$company, $branch, $owner] = $this->context();
        $item = $this->inventoryItem($company);
        InventoryStock::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => '10.000', 'average_cost' => '2.000000', 'minimum_quantity' => '3.000']);
        InventoryBatch::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity_received' => '3.000', 'quantity_remaining' => '3.000', 'unit_cost' => '2.000000', 'received_at' => now()->subDays(5), 'expires_at' => today()->subDay()]);
        $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $orderItem = OrderItem::factory()->for($order)->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'created_by' => $owner->id]);
        InventoryReservation::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'inventory_item_id' => $item->id, 'quantity' => '2.000', 'status' => InventoryReservationStatus::Reserved]);
        $this->movement($company, $branch, $item, $owner, InventoryMovementType::Waste, '1.000', '2.000000', '2.000000');
        $this->movement($company, $branch, $item, $owner, InventoryMovementType::OrderConsumption, '2.000', '2.000000', '4.000000', OrderItem::class, $orderItem->id);

        $service = app(InventoryReportService::class);
        $inventory = $service->inventory($company, $branch);
        $row = $inventory['items']->firstWhere('id', $item->id);
        $waste = $service->waste($company, $branch, $this->range());
        $consumption = $service->consumption($company, $branch, $this->range());

        $this->assertSame('10.000', $row->physical_quantity);
        $this->assertSame('3.000', $row->expired_quantity);
        $this->assertSame('2.000', $row->reserved_quantity);
        $this->assertSame('5.000', $row->available_quantity);
        $this->assertSame('20.000000000', $inventory['total_value']);
        $this->assertSame('2.00', $waste['total_cost']);
        $this->assertSame('4.00', $consumption['total_cost']);
    }

    public function test_estimated_result_uses_consumption_expenses_and_waste_but_does_not_subtract_purchases(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        $order = $this->paidOrder($company, $branch, $owner, $session, '100.00', now());
        $item = $this->inventoryItem($company);
        $this->movement($company, $branch, $item, $owner, InventoryMovementType::OrderConsumption, '1.000', '4.000000', '4.000000', OrderItem::class, $order->items()->first()->id);
        $this->movement($company, $branch, $item, $owner, InventoryMovementType::Waste, '1.000', '2.000000', '2.000000');
        $category = ExpenseCategory::factory()->for($company)->create();
        Expense::factory()->create($this->expenseAttributes($company, $branch, $owner, $category, '30.00'));
        $purchase = Purchase::factory()->for($branch)->create(['company_id' => $company->id, 'status' => PurchaseStatus::Posted, 'purchased_at' => now(), 'created_by' => $owner->id]);
        PurchaseItem::withoutEvents(fn () => PurchaseItem::query()->create(['company_id' => $company->id, 'purchase_id' => $purchase->id, 'inventory_item_id' => $item->id, 'quantity' => '50.000', 'input_unit_id' => $item->unit_id, 'base_quantity' => '50.000', 'unit_cost' => '2.000000', 'total_cost' => '100.000000']));

        $data = app(ProfitabilityReportService::class)->data($company, $branch, $this->range());
        $this->assertSame('4.00', $data['production_cost']);
        $this->assertSame('64.00', $data['estimated_result']);
        $this->assertStringContainsString('no se restan nuevamente', $data['methodology']);
    }

    public function test_cash_session_report_exposes_closing_difference_and_detail_without_crossing_branch(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        app(CloseCashSessionAction::class)->execute($session, '90.00', $owner, 'Faltante de prueba');
        $sessions = app(CashReportService::class)->paginate($company, $branch, $this->range());
        $row = $sessions->first();

        $this->assertSame('100.00', $row->expected_cash_amount);
        $this->assertSame('90.00', $row->counted_cash_amount);
        $this->assertSame('-10.00', $row->difference_amount);
        $this->assertSame($session->id, app(CashReportService::class)->detail($company, $branch, $session->ulid)->id);

        $otherBranch = Branch::factory()->for($company)->create();
        $this->expectException(ModelNotFoundException::class);
        app(CashReportService::class)->detail($company, $otherBranch, $session->ulid);
    }

    public function test_financial_report_permissions_are_restricted_while_cashier_has_limited_sales_access(): void
    {
        [$company, $branch, $owner] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);
        $cashier = $this->member($company, MembershipRole::Cashier);
        $waiter = $this->member($company, MembershipRole::Waiter);
        $kitchen = $this->member($company, MembershipRole::Kitchen);

        $this->actingInContext($owner, $company, $branch)->get(route('reports.index'))->assertOk();
        $this->actingInContext($admin, $company, $branch)->get(route('reports.profitability'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('reports.sales'))->assertForbidden();
        $this->actingInContext($cashier, $company, $branch)->get(route('reports.expenses'))->assertForbidden();
        $this->actingInContext($cashier, $company, $branch)->get(route('reports.export', 'sales'))->assertForbidden();
        $this->actingInContext($waiter, $company, $branch)->get(route('reports.sales'))->assertForbidden();
        $this->actingInContext($kitchen, $company, $branch)->get(route('reports.sales'))->assertForbidden();
    }

    public function test_csv_exports_apply_date_filters_and_company_branch_scope(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        $this->paidOrder($company, $branch, $owner, $session, '25.00', now(), orderNumber: 101);
        $this->paidOrder($company, $branch, $owner, $session, '30.00', now()->subDays(3), orderNumber: 102);
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = $this->member($otherCompany, MembershipRole::Owner);
        $otherRegister = CashRegister::query()->create(['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id, 'name' => 'Other', 'is_active' => true]);
        $otherSession = app(OpenCashSessionAction::class)->execute($otherRegister, '0.00', $otherOwner);
        $this->paidOrder($otherCompany, $otherBranch, $otherOwner, $otherSession, '999.00', now(), orderNumber: 999);

        $response = $this->actingInContext($owner, $company, $branch)->get(route('reports.export', ['report' => 'sales', 'preset' => 'today']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('#000101', $content);
        $this->assertStringNotContainsString('#000102', $content);
        $this->assertStringNotContainsString('#000999', $content);
    }

    public function test_report_queries_are_eager_loaded_and_money_code_has_no_binary_float_usage(): void
    {
        [$company, $branch, $owner, $session] = $this->context();
        for ($index = 1; $index <= 4; $index++) {
            $this->paidOrder($company, $branch, $owner, $session, '10.00', now(), orderNumber: 200 + $index);
        }
        \DB::enableQueryLog();
        app(SalesReportService::class)->data($company, $branch, $this->range());
        $this->assertLessThanOrEqual(10, count(\DB::getQueryLog()));

        foreach ([app_path('Services/SalesReportService.php'), app_path('Services/ProfitabilityReportService.php'), app_path('Services/ReportDecimalService.php')] as $file) {
            $this->assertDoesNotMatchRegularExpression('/floatval|doubleval|parseFloat|\(float\)|:\s*float\b/', file_get_contents($file));
        }
    }

    public function test_owner_can_render_every_report_tab_and_download_each_supported_export(): void
    {
        [$company, $branch, $owner, $session] = $this->context();

        foreach (['reports.index', 'reports.sales', 'reports.products', 'reports.cash', 'reports.expenses', 'reports.purchases', 'reports.inventory', 'reports.waste', 'reports.profitability'] as $route) {
            $this->actingInContext($owner, $company, $branch)->get(route($route))->assertOk();
        }

        $this->actingInContext($owner, $company, $branch)->get(route('reports.cash.show', $session->ulid))->assertOk();

        foreach (['sales', 'expenses', 'purchases', 'inventory', 'cash'] as $report) {
            $this->actingInContext($owner, $company, $branch)->get(route('reports.export', $report))->assertOk();
        }
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = $this->member($company, MembershipRole::Owner);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja Principal', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $owner);

        return [$company, $branch, $owner, $session];
    }

    private function paidOrder(Company $company, Branch $branch, User $user, CashSession $session, string $total, DateTimeInterface $closedAt, ?ProductVariant $variant = null, bool $createPayment = true, ?int $orderNumber = null): Order
    {
        $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'order_number' => $orderNumber ?? fake()->unique()->numberBetween(1000, 9999), 'status' => OrderStatus::Paid, 'type' => OrderType::Takeaway, 'subtotal' => $total, 'total' => $total, 'closed_at' => $closedAt, 'created_by' => $user->id]);
        $itemAttributes = ['company_id' => $company->id, 'branch_id' => $branch->id, 'quantity' => '1.000', 'unit_price' => $total, 'line_total' => $total, 'status' => OrderItemStatus::Served, 'created_by' => $user->id];
        if ($variant) {
            $itemAttributes['product_variant_id'] = $variant->id;
        }
        OrderItem::factory()->for($order)->create($itemAttributes);
        if ($createPayment && $session->branch_id === $branch->id) {
            $this->payment($order, $session, $user, PaymentMethod::Cash, $total, PaymentStatus::Completed, 'order-'.$order->id);
        }

        return $order;
    }

    private function payment(Order $order, CashSession $session, User $user, PaymentMethod $method, string $amount, PaymentStatus $status, string $key): Payment
    {
        return Payment::query()->create(['company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id, 'cash_session_id' => $session->id, 'method' => $method, 'amount' => $amount, 'paid_at' => now(), 'received_by' => $user->id, 'status' => $status, 'idempotency_key' => $key]);
    }

    private function inventoryItem(Company $company): InventoryItem
    {
        $unit = Unit::factory()->for($company)->create(['symbol' => 'kg']);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();

        return InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'ingredient_id' => $ingredient->id, 'name' => $ingredient->name, 'is_active' => true]);
    }

    private function movement(Company $company, Branch $branch, InventoryItem $item, User $user, InventoryMovementType $type, string $quantity, string $unitCost, string $totalCost, ?string $referenceType = null, ?int $referenceId = null): InventoryMovement
    {
        return InventoryMovement::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'type' => $type, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $totalCost, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'occurred_at' => now(), 'created_by' => $user->id]);
    }

    private function expenseAttributes(Company $company, Branch $branch, User $user, ExpenseCategory $category, string $amount): array
    {
        return ['company_id' => $company->id, 'branch_id' => $branch->id, 'expense_category_id' => $category->id, 'amount' => $amount, 'expense_date' => CarbonImmutable::now(config('reports.timezone'))->toDateString(), 'description' => 'Servicio', 'document_type' => ExpenseDocumentType::WithoutInvoice, 'payment_method' => ExpensePaymentMethod::Qr, 'status' => ExpenseStatus::Posted, 'created_by' => $user->id];
    }

    private function range(string $preset = 'today', ?string $from = null, ?string $to = null): ReportDateRange
    {
        return app(ReportDateRangeService::class)->from(array_filter(['preset' => $preset, 'date_from' => $from, 'date_to' => $to]));
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
