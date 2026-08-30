<?php

namespace Tests\Feature;

use App\Actions\OpenCashSessionAction;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_history_only_contains_batches_and_items_from_the_requested_order(): void
    {
        [$company, $branch, $owner] = $this->context();
        $order = $this->order($company, $branch, $owner, 'Mesa actual');
        $this->batch($order, $owner, 'Producto de esta cuenta', '35.00');

        $otherOrder = $this->order($company, $branch, $owner, 'Otra mesa');
        $this->batch($otherOrder, $owner, 'Producto de otra cuenta', '88.00');

        $history = app(OrderHistoryService::class)->forOrder($order);
        $names = collect($history['batches'])->flatMap(fn (array $batch) => collect($batch['items'])->pluck('name'));

        $this->assertCount(1, $history['batches']);
        $this->assertTrue($names->contains(fn (string $name): bool => str_contains($name, 'Producto de esta cuenta')));
        $this->assertFalse($names->contains(fn (string $name): bool => str_contains($name, 'Producto de otra cuenta')));
    }

    public function test_order_screen_rejects_orders_from_another_company_or_branch(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();
        $otherBranchOrder = $this->order($company, $otherBranch, $owner, 'Sucursal ajena');

        $otherCompany = Company::factory()->create();
        $foreignBranch = Branch::factory()->for($otherCompany)->create();
        $foreignOrder = Order::factory()->for($foreignBranch)->create([
            'company_id' => $otherCompany->id,
            'created_by' => $owner->id,
        ]);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.show', $otherBranchOrder->ulid))
            ->assertNotFound();
        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.show', $foreignOrder->ulid))
            ->assertNotFound();
    }

    public function test_paid_batch_shows_its_completed_mixed_payment_and_get_is_read_only(): void
    {
        [$company, $branch, $owner] = $this->context();
        $order = $this->order($company, $branch, $owner, 'Mesa mixta', '100.00');
        [$dispatch] = $this->batch($order, $owner, 'Pizza historial', '100.00', KitchenDispatchStatus::Settled);
        $session = $this->cashSession($company, $branch, $owner);
        $this->payment($order, $dispatch, $session->id, $owner, PaymentMethod::Cash, '40.00', 'history-cash');
        $this->payment($order, $dispatch, $session->id, $owner, PaymentMethod::Qr, '60.00', 'history-qr');

        $counts = [
            Order::query()->count(),
            OrderItem::query()->count(),
            KitchenDispatch::query()->count(),
            Payment::query()->count(),
        ];
        $orderUpdatedAt = $order->updated_at;
        $dispatchUpdatedAt = $dispatch->updated_at;

        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('HISTORIAL DE LA CUENTA')
            ->assertSee('PAGADA')
            ->assertSee('Pago: Mixto')
            ->assertSee('Efectivo Bs 40,00 + QR Bs 60,00')
            ->assertSee('Pizza historial')
            ->assertDontSee('Payment ID')
            ->assertDontSee('Reimprimir');

        $this->assertSame($counts, [
            Order::query()->count(),
            OrderItem::query()->count(),
            KitchenDispatch::query()->count(),
            Payment::query()->count(),
        ]);
        $this->assertTrue($orderUpdatedAt->equalTo($order->fresh()->updated_at));
        $this->assertTrue($dispatchUpdatedAt->equalTo($dispatch->fresh()->updated_at));
    }

    public function test_pending_batch_uses_existing_payments_for_its_balance(): void
    {
        [$company, $branch, $owner] = $this->context();
        $order = $this->order($company, $branch, $owner, 'Mesa pendiente', '107.00');
        [$dispatch] = $this->batch($order, $owner, 'Pizza pendiente', '107.00');
        $session = $this->cashSession($company, $branch, $owner);
        $this->payment($order, $dispatch, $session->id, $owner, PaymentMethod::Cash, '40.00', 'history-partial');

        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('PENDIENTE DE PAGO')
            ->assertSee('Saldo de la tanda: Bs 67,00');
    }

    private function context(): array
    {
        $company = Company::factory()->create(['table_charge_mode' => TableChargeMode::PerBatch]);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);

        return [$company, $branch, $owner];
    }

    private function order(Company $company, Branch $branch, User $owner, string $customer, string $total = '35.00'): Order
    {
        return Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'type' => OrderType::DineIn,
            'charge_mode' => TableChargeMode::PerBatch,
            'customer_name' => $customer,
            'subtotal' => $total,
            'other_subtotal' => $total,
            'total' => $total,
            'created_by' => $owner->id,
        ]);
    }

    private function batch(
        Order $order,
        User $owner,
        string $productName,
        string $total,
        KitchenDispatchStatus $status = KitchenDispatchStatus::AwaitingPayment,
    ): array {
        $product = Product::factory()->create([
            'company_id' => $order->company_id,
            'name' => $productName,
            'type' => ProductType::Beverage,
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'company_id' => $order->company_id,
            'name' => 'Unidad',
            'price' => $total,
            'requires_preparation' => false,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'product_variant_id' => $variant->id,
            'quantity' => '1.000',
            'unit_price' => $total,
            'line_total' => $total,
            'status' => $status === KitchenDispatchStatus::Settled ? OrderItemStatus::Ready : OrderItemStatus::PendingPayment,
            'created_by' => $owner->id,
        ]);
        $dispatch = KitchenDispatch::query()->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'sequence_number' => 1,
            'status' => $status,
            'dispatched_at' => now(),
            'dispatched_by' => $owner->id,
            'gross_subtotal' => $total,
            'other_subtotal' => $total,
            'total' => $total,
            'settled_at' => $status === KitchenDispatchStatus::Settled ? now() : null,
        ]);
        KitchenDispatchItem::query()->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'kitchen_dispatch_id' => $dispatch->id,
            'order_item_id' => $item->id,
            'financial_type' => 'other',
            'gross_total' => $total,
            'other_total' => $total,
            'net_total' => $total,
        ]);

        return [$dispatch, $item];
    }

    private function cashSession(Company $company, Branch $branch, User $owner)
    {
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja',
            'is_active' => true,
        ]);

        return app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
    }

    private function payment(Order $order, KitchenDispatch $dispatch, int $cashSessionId, User $owner, PaymentMethod $method, string $amount, string $key): Payment
    {
        return Payment::query()->create([
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'kitchen_dispatch_id' => $dispatch->id,
            'cash_session_id' => $cashSessionId,
            'method' => $method,
            'amount' => $amount,
            'received_amount' => $method === PaymentMethod::Cash ? $amount : null,
            'change_amount' => $method === PaymentMethod::Cash ? '0.00' : null,
            'paid_at' => now(),
            'received_by' => $owner->id,
            'status' => PaymentStatus::Completed,
            'idempotency_key' => $key,
        ]);
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
