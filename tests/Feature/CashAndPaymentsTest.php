<?php

namespace Tests\Feature;

use App\Actions\CancelOrderAction;
use App\Actions\CloseCashSessionAction;
use App\Actions\ManualCashMovementAction;
use App\Actions\MarkOrderItemServedAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\ReversePaymentAction;
use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\OrderPaymentService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_register_and_opening_session_create_one_opening_ledger_entry(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '200.00', $owner, 'Inicio de turno');

        $this->assertSame(CashSessionStatus::Open, $session->status);
        $this->assertSame('200.00', $session->opening_amount);
        $this->assertSame($register->id, $session->active_cash_register_id);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $session->id,
            'type' => CashMovementType::Opening->value,
            'amount' => 200,
        ]);

        $this->expectException(DomainException::class);
        app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
    }

    public function test_cash_payment_records_net_cash_change_and_is_idempotent(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '200.00', $owner);
        $order = $this->servedOrder($company, $branch, $owner, '85.00');
        $beforeInventory = InventoryMovement::query()->count();

        $payment = app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '85.00', $owner, 'cash-click-1', '100.00');
        $duplicate = app(RegisterPaymentAction::class)->execute($order->refresh(), $session, PaymentMethod::Cash, '85.00', $owner, 'cash-click-1', '100.00');

        $this->assertSame($payment->id, $duplicate->id);
        $this->assertSame('100.00', $payment->received_amount);
        $this->assertSame('15.00', $payment->change_amount);
        $this->assertDatabaseHas('cash_movements', ['type' => CashMovementType::SaleCash->value, 'amount' => 85]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($beforeInventory, InventoryMovement::query()->count());
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_qr_partial_payment_does_not_change_physical_cash_or_release_table(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '50.00', $owner);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = $this->servedOrder($company, $branch, $owner, '200.00', $table);

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '100.00', $owner, 'qr-partial', reference: 'QR-001');
        $summary = app(CashSessionSummaryService::class)->calculate($session);

        $this->assertSame('100.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('100.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame(OrderStatus::ReadyForPayment, $order->refresh()->status);
        $this->assertSame($table->id, $order->active_restaurant_table_id);
        $this->assertSame('50.00', $summary['expected_cash']);
        $this->assertSame('100.00', $summary['qr_payments']);
        $this->assertSame(0, $summary['orders_paid']);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_mixed_payments_are_separate_and_full_payment_releases_table(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = $this->servedOrder($company, $branch, $owner, '180.00', $table);

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '100.00', $owner, 'mixed-qr');
        app(RegisterPaymentAction::class)->execute($order->refresh(), $session, PaymentMethod::Cash, '80.00', $owner, 'mixed-cash', '80.00');

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['method' => PaymentMethod::Qr->value, 'amount' => 100]);
        $this->assertDatabaseHas('payments', ['method' => PaymentMethod::Cash->value, 'amount' => 80]);
        $this->assertSame('0.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertNotNull($order->closed_at);
        $this->assertSame(1, app(CashSessionSummaryService::class)->calculate($session)['orders_paid']);
    }

    public function test_full_payment_can_wait_for_kitchen_and_finalizes_after_the_last_item_is_served(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = $this->servedOrder($company, $branch, $owner, '10.00', $table);
        $item = $order->items()->firstOrFail();
        $item->forceFill([
            'status' => OrderItemStatus::Ready,
            'requires_preparation' => true,
            'ready_at' => now(),
            'served_at' => null,
        ])->save();
        $reservation = $order->reservations()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'order_item_id' => $item->id,
            'inventory_item_id' => $this->inventoryItem($company)->id,
            'quantity' => '1.000',
            'status' => InventoryReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '10.00', $owner, 'advance-full');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(OrderStatus::ReadyForPayment, $order->refresh()->status);
        $this->assertSame($table->id, $order->active_restaurant_table_id);
        $this->assertNull($order->closed_at);
        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.checkout', $order->ulid))
            ->assertOk()
            ->assertSee('Pago completado; pedido aún operativo.')
            ->assertSee('Aún hay productos pendientes en cocina.');

        $reservation->forceFill([
            'status' => InventoryReservationStatus::Consumed,
            'consumed_at' => now(),
        ])->save();
        app(MarkOrderItemServedAction::class)->execute($item->refresh(), $owner);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertNotNull($order->closed_at);
    }

    public function test_manual_movements_expected_cash_and_closing_differences_are_decimal_safe(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '200.00', $owner);
        app(ManualCashMovementAction::class)->execute($session, CashMovementType::ManualIn, '50.00', 'Cambio adicional', $owner);
        app(ManualCashMovementAction::class)->execute($session, CashMovementType::ManualOut, '100.00', 'Compra de bolsas', $owner);
        $order = $this->servedOrder($company, $branch, $owner, '85.00');
        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '85.00', $owner, 'closing-sale', '100.00');

        $summary = app(CashSessionSummaryService::class)->calculate($session);
        $this->assertSame('235.00', $summary['expected_cash']);
        $closed = app(CloseCashSessionAction::class)->execute($session, '225.00', $owner, 'Faltante verificado');
        $this->assertSame(CashSessionStatus::Closed, $closed->status);
        $this->assertSame('235.00', $closed->expected_cash_amount);
        $this->assertSame('-10.00', $closed->difference_amount);
        $this->assertNull($closed->active_cash_register_id);

        $same = app(CloseCashSessionAction::class)->execute($closed, '999.00', $owner);
        $this->assertSame($closed->id, $same->id);
        $this->assertSame('-10.00', $same->difference_amount);

        $next = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $positive = app(CloseCashSessionAction::class)->execute($next, '10.00', $owner, 'Sobrante verificado');
        $this->assertSame('10.00', $positive->difference_amount);
    }

    public function test_cash_payment_reversal_creates_compensating_history_and_cannot_repeat(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $owner);
        $order = $this->servedOrder($company, $branch, $owner, '100.00');
        $payment = app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '40.00', $owner, 'partial-reverse', '40.00');

        $reversal = app(ReversePaymentAction::class)->execute($payment, 'Método incorrecto', $owner);

        $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
        $this->assertSame($payment->id, $reversal->reversal_of_id);
        $this->assertDatabaseHas('cash_movements', ['type' => CashMovementType::Reversal->value, 'amount' => 40]);
        $this->assertSame('0.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('100.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);

        $this->expectException(DomainException::class);
        app(ReversePaymentAction::class)->execute($payment->refresh(), 'Otra vez', $owner);
    }

    public function test_order_with_payment_cannot_be_cancelled_and_payment_requires_open_session_in_ui(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $order = $this->servedOrder($company, $branch, $owner, '100.00');
        $this->actingInContext($owner, $company, $branch)
            ->post(route('orders.payments.store', $order->ulid), [
                'method' => 'cash', 'amount' => '10.00', 'received_amount' => '10.00', 'idempotency_key' => 'no-session',
            ])->assertSessionHasErrors('payment');

        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '20.00', $owner, 'partial-before-cancel');

        $this->expectException(DomainException::class);
        app(CancelOrderAction::class)->execute($order, $owner);
    }

    public function test_paid_order_cannot_be_cancelled_without_a_financial_reversal_workflow(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $order = $this->servedOrder($company, $branch, $owner, '100.00');
        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '100.00', $owner, 'paid-before-cancel');

        $this->expectException(DomainException::class);
        app(CancelOrderAction::class)->execute($order->refresh(), $owner);
    }

    public function test_payment_rejects_a_cash_session_from_another_branch(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();
        $otherRegister = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Caja secundaria',
            'is_active' => true,
        ]);
        $otherSession = app(OpenCashSessionAction::class)->execute($otherRegister, '0.00', $owner);
        $order = $this->servedOrder($company, $branch, $owner, '100.00');

        try {
            app(RegisterPaymentAction::class)->execute($order, $otherSession, PaymentMethod::Qr, '100.00', $owner, 'wrong-branch');
            $this->fail('A payment cannot cross branch boundaries.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(OrderStatus::Open, $order->refresh()->status);
        }
    }

    public function test_cash_access_is_isolated_and_matches_operational_roles(): void
    {
        [$company, $branch, $owner] = $this->context();
        $cashier = User::factory()->create();
        Membership::factory()->for($company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);

        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))->assertOk();
        $this->actingInContext($waiter, $company, $branch)->get(route('cash.index'))->assertForbidden();
        $this->actingInContext($kitchen, $company, $branch)->get(route('cash.index'))->assertForbidden();

        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingInContext($otherOwner, $otherCompany, $otherBranch)->get(route('cash.index'))->assertOk()->assertDontSee('Caja Principal');
    }

    public function test_owner_sees_only_active_registers_from_current_branch_on_open_form(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();
        CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja inactiva',
            'is_active' => false,
        ]);
        CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Caja otra sucursal',
            'is_active' => true,
        ]);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('cash.open.form'))
            ->assertOk()
            ->assertSee($register->name)
            ->assertDontSee('Caja inactiva')
            ->assertDontSee('Caja otra sucursal');
    }

    public function test_owner_can_open_session_from_http_and_cannot_open_a_second_one_for_the_register(): void
    {
        [$company, $branch, $owner, $register] = $this->context();

        $this->actingInContext($owner, $company, $branch)
            ->post(route('cash.open'), [
                'register' => $register->ulid,
                'opening_amount' => '125.50',
                'notes' => 'Inicio de turno',
            ])
            ->assertRedirect(route('cash.current'));

        $this->assertDatabaseHas('cash_sessions', [
            'cash_register_id' => $register->id,
            'active_cash_register_id' => $register->id,
            'opening_amount' => 125.50,
        ]);

        $this->actingInContext($owner, $company, $branch)
            ->from(route('cash.open.form'))
            ->post(route('cash.open'), [
                'register' => $register->ulid,
                'opening_amount' => '0.00',
            ])
            ->assertRedirect(route('cash.open.form'))
            ->assertSessionHasErrors('cash');

        $this->assertDatabaseCount('cash_sessions', 1);
    }

    public function test_checkout_renders_cash_and_qr_without_exposing_it_to_waiter(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $order = $this->servedOrder($company, $branch, $owner, '70.00');
        $cashier = User::factory()->create();
        Membership::factory()->for($company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);

        $this->actingInContext($cashier, $company, $branch)
            ->get(route('orders.checkout', $order->ulid))
            ->assertOk()
            ->assertSee('Efectivo')
            ->assertSee('QR')
            ->assertSee('Bs 70,00');

        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $this->actingInContext($waiter, $company, $branch)
            ->get(route('orders.checkout', $order->ulid))
            ->assertOk()
            ->assertDontSee('Confirmar efectivo');
    }

    public function test_checkout_uses_sequential_payments_and_prefills_the_remaining_balance(): void
    {
        [$company, $branch, $owner, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $order = $this->servedOrder($company, $branch, $owner, '140.00');
        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '100.00', $owner, 'cash-sequential', '100.00');

        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.checkout', $order->ulid))
            ->assertOk()
            ->assertSee('¿Cómo paga el restante?')
            ->assertSee('Bs 100,00')
            ->assertSee('Bs 40,00')
            ->assertSee('value="40.00"', false)
            ->assertSee('data-payment-form="cash" data-cash-payment hidden', false)
            ->assertSee('data-payment-form="qr" hidden', false)
            ->assertSee('Registrar pago en efectivo')
            ->assertSee('Registrar pago QR')
            ->assertSee('Vuelto');
    }

    public function test_cash_register_seeder_is_idempotent_without_opening_a_session(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('cash_registers', 1);
        $this->assertDatabaseHas('cash_registers', ['name' => 'Caja Principal', 'is_active' => true]);
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);

        return [$company, $branch, $owner, $register];
    }

    private function servedOrder(Company $company, Branch $branch, User $owner, string $total, ?RestaurantTable $table = null): Order
    {
        $order = Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'restaurant_table_id' => $table?->id,
            'active_restaurant_table_id' => $table?->id,
            'type' => $table ? OrderType::DineIn : OrderType::Takeaway,
            'subtotal' => $total,
            'total' => $total,
            'created_by' => $owner->id,
        ]);
        OrderItem::factory()->for($order)->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'line_total' => $total,
            'unit_price' => $total,
            'status' => OrderItemStatus::Served,
            'served_at' => now(),
            'created_by' => $owner->id,
        ]);

        return $order;
    }

    private function inventoryItem(Company $company): InventoryItem
    {
        $unit = Unit::factory()->for($company)->create();
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();

        return InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'name' => $ingredient->name,
            'is_active' => true,
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
