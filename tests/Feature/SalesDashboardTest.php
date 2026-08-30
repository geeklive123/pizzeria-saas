<?php

namespace Tests\Feature;

use App\Actions\OpenCashSessionAction;
use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_sale_screen_uses_scoped_real_metrics_pending_state_and_available_stock(): void
    {
        [$company, $branch, $owner] = $this->context();
        $table = RestaurantTable::factory()->for($branch)->create([
            'company_id' => $company->id,
            'name' => 'Mesa Métrica',
            'is_active' => true,
        ]);
        RestaurantTable::factory()->for($branch)->create([
            'company_id' => $company->id,
            'name' => 'Mesa Libre',
            'is_active' => true,
        ]);
        $pendingOrder = Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'restaurant_table_id' => $table->id,
            'active_restaurant_table_id' => $table->id,
            'status' => OrderStatus::ReadyForPayment,
            'charge_mode' => TableChargeMode::AtEnd,
            'customer_name' => 'Cliente real',
            'total' => '38.00',
            'opened_at' => now()->subMinutes(12),
            'created_by' => $owner->id,
        ]);
        $paidOrder = Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'type' => OrderType::Takeaway,
            'status' => OrderStatus::Paid,
            'total' => '50.00',
            'created_by' => $owner->id,
        ]);
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja principal',
            'is_active' => true,
        ]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $this->payment($paidOrder, $session->id, $owner, PaymentMethod::Qr, '20.00', 'qr-today');
        $this->payment($paidOrder, $session->id, $owner, PaymentMethod::Cash, '30.00', 'cash-today');
        $this->payment($paidOrder, $session->id, $owner, PaymentMethod::Qr, '99.00', 'qr-reversed', PaymentStatus::Reversed);

        $unit = Unit::factory()->for($company)->create(['name' => 'Kilogramo', 'symbol' => 'kg', 'type' => UnitType::Weight]);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();
        $item = InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Queso bajo real',
            'is_active' => true,
        ]);
        InventoryStock::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '1.200',
            'minimum_quantity' => '2.000',
        ]);

        $foreignCompany = Company::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignCompany)->create();
        Order::factory()->for($foreignBranch)->create([
            'company_id' => $foreignCompany->id,
            'status' => OrderStatus::Open,
        ]);

        $response = $this->asUser($owner, $company, $branch)->get(route('sales.create'));

        $response->assertOk()
            ->assertSee('1 <span class="text-base font-medium text-stone-400">/ 2</span>', false)
            ->assertSee('50% ocupación')
            ->assertSee('Bs 50,00')
            ->assertSee('Bs 20,00')
            ->assertSee('1 pago')
            ->assertSee('Mesa Métrica')
            ->assertSee('Cliente real')
            ->assertSee('Pago pendiente')
            ->assertSee('Queso bajo real')
            ->assertSee('1,2 kg')
            ->assertDontSee('99,00');

        $this->assertSame($pendingOrder->id, $table->openOrder()->firstOrFail()->id);
    }

    public function test_cashier_dashboard_redirects_to_sales_and_navigation_is_operational(): void
    {
        [$company, $branch] = $this->context();
        $cashier = User::factory()->create();
        Membership::factory()->for($company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'Mesa visible en Venta']);

        $this->asUser($cashier, $company, $branch)->get(route('dashboard'))
            ->assertRedirect(route('sales.create'));

        $this->asUser($cashier, $company, $branch)->get(route('sales.create'))
            ->assertOk()
            ->assertSee('Venta')
            ->assertSee('Pedidos')
            ->assertSee('Caja')
            ->assertSee('Inventario')
            ->assertSee('Reportes')
            ->assertDontSee('href="'.route('tables.index').'"', false)
            ->assertSee('Mesa visible en Venta')
            ->assertDontSee('Inicio')
            ->assertDontSee('Productos');
    }

    public function test_free_table_keeps_inline_charge_mode_and_takeaway_forms(): void
    {
        [$company, $branch, $owner] = $this->context();
        RestaurantTable::factory()->for($branch)->create([
            'company_id' => $company->id,
            'name' => 'Mesa Inline',
            'is_active' => true,
        ]);

        $this->asUser($owner, $company, $branch)->get(route('sales.create'))
            ->assertOk()
            ->assertSee('Mesa Inline')
            ->assertSee('Abrir cuenta')
            ->assertSee('¿Cómo se cobrará esta mesa?')
            ->assertSee('Por tanda')
            ->assertSee('Al final')
            ->assertSee('Abrir pedido para llevar');
    }

    private function context(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $branch = Branch::factory()->for($company)->create(['is_active' => true]);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();

        return [$company, $branch, $owner];
    }

    private function payment(Order $order, int $sessionId, User $user, PaymentMethod $method, string $amount, string $key, PaymentStatus $status = PaymentStatus::Completed): Payment
    {
        return Payment::query()->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'cash_session_id' => $sessionId,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => now(),
            'received_by' => $user->id,
            'status' => $status,
            'idempotency_key' => $key,
        ]);
    }

    private function asUser(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
