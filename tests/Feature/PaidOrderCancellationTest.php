<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\AddStandaloneExtraAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelPaidOrderAction;
use App\Actions\CloseCashSessionAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\RestoreCancelledPaidOrderAction;
use App\Actions\ReverseInventoryMovementAction;
use App\Actions\SaveToppingAction;
use App\Actions\TransferOrderPaymentsToCashSessionAction;
use App\Enums\CashMovementType;
use App\Enums\InventoryMovementType;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\OrderPaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaidOrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    public static function paymentCases(): array
    {
        return [
            'owner cash' => [MembershipRole::Owner, ['cash' => '10.00']],
            'admin qr' => [MembershipRole::Admin, ['qr' => '10.00']],
            'mixed' => [MembershipRole::Owner, ['cash' => '4.00', 'qr' => '6.00']],
        ];
    }

    #[DataProvider('paymentCases')]
    public function test_paid_cancellation_preserves_history_and_reverses_each_payment(MembershipRole $role, array $amounts): void
    {
        $f = $this->fixture($role, $amounts);
        extract($f);
        $originalPayments = $order->payments()->get();
        $originalMovements = CashMovement::all()->map->getRawOriginal()->all();
        $number = $order->operational_number;
        $closedAt = $order->closed_at->toIso8601String();
        $dispatch = $order->kitchenDispatches()->firstOrFail();
        // A legacy paid order may still retain its operational table link.
        $order->forceFill(['active_restaurant_table_id' => $table->id])->save();

        $this->actingInContext($f)->get(route('orders.index'))->assertOk()->assertSee('ANULAR Y REVERTIR');
        $this->post(route('orders.cancel-paid', $order->ulid), ['reason' => 'Venta duplicada'])
            ->assertRedirect(route('orders.index'))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame('Venta duplicada', $order->cancellation_reason);
        $this->assertSame($actor->id, $order->cancelled_by);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame($number, $order->operational_number);
        $this->assertSame($closedAt, $order->closed_at->toIso8601String());
        $this->assertSame('10.00', $order->total);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertSame($table->id, $order->restaurant_table_id);
        $this->assertSame(KitchenDispatchStatus::Cancelled, $dispatch->refresh()->status);
        $this->assertSame('0.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame('0.00', app(OrderPaymentService::class)->dispatchBalance($dispatch));
        $this->assertDatabaseCount('kitchen_dispatches', 1);
        $this->assertDatabaseCount('order_items', 1);
        foreach ($originalPayments as $payment) {
            $before = $payment->getRawOriginal();
            $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
            foreach (['amount', 'method', 'paid_at', 'received_by', 'reference', 'received_amount', 'change_amount'] as $field) {
                $this->assertSame($before[$field], $payment->getRawOriginal($field));
            }
            $reversal = $payment->reversals()->sole();
            $this->assertSame($payment->amount, $reversal->amount);
            $this->assertSame($actor->id, $reversal->received_by);
            $this->assertSame('Venta duplicada', $reversal->reference);
        }
        $this->assertDatabaseCount('payments', count($amounts) * 2);
        foreach ($originalMovements as $movement) {
            $this->assertSame($movement, CashMovement::findOrFail($movement['id'])->getRawOriginal());
        }
        $this->assertSame(isset($amounts['cash']) ? 1 : 0, CashMovement::where('type', CashMovementType::Reversal)->count());
        $this->assertSame('20.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        $this->assertSame('100.000', $inventory->inventoryStocks()->sole()->quantity);
        $this->assertSame('100.000', InventoryBatch::where('inventory_item_id', $inventory->id)->sole()->quantity_remaining);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reversal)->count());
        $this->get(route('orders.index'))->assertOk()->assertSee('ANULADO')->assertSee('Venta duplicada')->assertSee($actor->name);
        $this->get(route('orders.show', $order->ulid))->assertOk()->assertSee('ANULADO')->assertSee('Venta duplicada');
        $this->post(route('orders.cancel-paid', $order->ulid), ['reason' => 'Otra vez'])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('payments', count($amounts) * 2);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reversal)->count());
    }

    public static function forbiddenRoles(): array
    {
        return [[MembershipRole::Cashier], [MembershipRole::Waiter], [MembershipRole::Kitchen]];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_other_roles_cannot_see_or_execute_paid_cancellation(MembershipRole $role): void
    {
        $f = $this->fixture();
        $user = User::factory()->create();
        Membership::factory()->for($f['company'])->for($user)->create(['role' => $role]);
        $this->actingInContext([...$f, 'actor' => $user])->get(route('orders.index'))->assertDontSee('ANULAR Y REVERTIR');
        $this->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'Sin permiso'])->assertForbidden();
        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_admin_permission_overrides_are_respected(): void
    {
        $f = $this->fixture(MembershipRole::Admin);
        $membership = $f['actor']->membershipFor($f['company']);
        $membership->permissionOverrides()->create([
            'company_id' => $f['company']->id,
            'permission' => Permission::ReversePayments,
            'allowed' => false,
        ]);
        $this->actingInContext($f)->get(route('orders.index'))->assertOk()->assertDontSee('ANULAR Y REVERTIR');
        $this->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'Sin permiso'])->assertForbidden();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reason_is_required_and_non_paid_orders_are_rejected(): void
    {
        $f = $this->fixture();
        $this->actingInContext($f)->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => ' '])->assertSessionHasErrors('reason');
        $f['order']->forceFill(['status' => OrderStatus::ReadyForPayment])->save();
        $this->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'No pagado'])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_tenant_and_branch_boundaries_are_preserved(): void
    {
        $f = $this->fixture();
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingInContext(['actor' => $otherOwner, 'company' => $otherCompany, 'branch' => $otherBranch])
            ->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'Otra empresa'])->assertNotFound();
        $branch = Branch::factory()->for($f['company'])->create();
        $this->actingInContext([...$f, 'branch' => $branch])
            ->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'Otra sucursal'])->assertNotFound();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_inventory_failure_rolls_back_payments_cash_and_table_release(): void
    {
        $f = $this->fixture(amounts: ['cash' => '4.00', 'qr' => '6.00']);
        $f['order']->forceFill(['active_restaurant_table_id' => $f['table']->id])->save();
        $before = $f['order']->getRawOriginal();
        $this->mock(ReverseInventoryMovementAction::class)->shouldReceive('execute')->once()->andThrow(new DomainException('Fallo de inventario'));
        $this->actingInContext($f)->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'Prueba rollback'])->assertSessionHasErrors('order');
        $this->assertSame($before, $f['order']->refresh()->getRawOriginal());
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(2, Payment::where('status', PaymentStatus::Completed)->count());
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertSame('80.000', $f['inventory']->inventoryStocks()->sole()->quantity);
        $this->assertSame(KitchenDispatchStatus::Settled, $f['order']->kitchenDispatches()->sole()->status);
    }

    public function test_closed_cash_session_rejects_the_entire_operation(): void
    {
        $f = $this->fixture(amounts: ['cash' => '4.00', 'qr' => '6.00']);
        app(CloseCashSessionAction::class)->execute($f['session'], '24.00', $f['actor']);
        $snapshot = $f['session']->refresh()->getRawOriginal();
        $this->actingInContext($f)->post(route('orders.cancel-paid', $f['order']->ulid), ['reason' => 'Turno cerrado'])->assertSessionHasErrors('order');
        $this->assertSame($snapshot, $f['session']->refresh()->getRawOriginal());
        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertSame(2, Payment::where('status', PaymentStatus::Completed)->count());
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_direct_product_consumption_is_returned_through_the_ledger(): void
    {
        $f = $this->fixture(standalone: false);
        $consumption = InventoryMovement::where('type', InventoryMovementType::OrderConsumption)->sole();
        $snapshot = $consumption->getRawOriginal();
        $this->assertSame('98.000', $f['inventory']->inventoryStocks()->sole()->quantity);
        app(CancelPaidOrderAction::class)->execute($f['order'], $f['actor'], 'Devolución');
        $this->assertSame('100.000', $f['inventory']->inventoryStocks()->sole()->quantity);
        $this->assertSame($snapshot, $consumption->refresh()->getRawOriginal());
        $this->assertSame('2.000', $consumption->reversals()->sole()->quantity);
    }

    public function test_cash_transferred_to_another_session_is_reversed_in_its_current_session(): void
    {
        $f = $this->fixture();
        $other = User::factory()->create();
        Membership::factory()->for($f['company'])->for($other)->create(['role' => MembershipRole::Cashier]);
        $register = CashRegister::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'name' => 'Caja 2', 'is_active' => true]);
        $destination = app(OpenCashSessionAction::class)->execute($register, '0.00', $other);
        app(TransferOrderPaymentsToCashSessionAction::class)->execute($f['company'], $f['order'], $destination, $f['actor'], 'Cambio de caja');
        $entry = CashMovement::where('cash_session_id', $destination->id)->where('type', CashMovementType::AdministrativeTransferIn)->sole();
        app(CancelPaidOrderAction::class)->execute($f['order'], $f['actor'], 'Devolución');
        $this->assertDatabaseHas('cash_movements', ['type' => 'reversal', 'cash_session_id' => $destination->id, 'reversal_of_id' => $entry->id]);
        $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($destination)['expected_cash']);
        $this->assertSame('20.00', app(CashSessionSummaryService::class)->calculate($f['session'])['expected_cash']);
    }

    #[DataProvider('paymentCases')]
    public function test_cancelled_paid_order_can_be_restored_atomically_with_compensating_payments(MembershipRole $role, array $amounts): void
    {
        $f = $this->fixture($role, $amounts);
        $number = $f['order']->operational_number;
        $total = $f['order']->total;
        $closedAt = $f['order']->closed_at->toIso8601String();
        $previousItemStatus = $f['item']->refresh()->status;
        app(CancelPaidOrderAction::class)->execute($f['order'], $f['actor'], 'Comanda incorrecta');

        app(RestoreCancelledPaidOrderAction::class)->execute($f['order']->refresh(), $f['actor'], 'Era la venta correcta');

        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertSame($number, $f['order']->operational_number);
        $this->assertSame($total, $f['order']->total);
        $this->assertSame($closedAt, $f['order']->closed_at->toIso8601String());
        $this->assertSame($previousItemStatus, $f['item']->refresh()->status);
        $this->assertSame('80.000', $f['inventory']->inventoryStocks()->sole()->quantity);
        $this->assertDatabaseCount('payments', count($amounts) * 3);
        $this->assertSame(count($amounts), Payment::query()->where('status', PaymentStatus::Completed->value)->count());
        foreach ($amounts as $method => $amount) {
            $this->assertDatabaseHas('payments', [
                'order_id' => $f['order']->id,
                'method' => $method,
                'amount' => $amount,
                'status' => PaymentStatus::Completed->value,
            ]);
        }
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Reversal->value)->count());
        $this->assertSame(isset($amounts['cash']) ? 2 : 0, CashMovement::query()->where('type', CashMovementType::SaleCash->value)->count());
        $this->assertSame(isset($amounts['cash']) ? 1 : 0, CashMovement::query()->where('type', CashMovementType::Reversal->value)->count());
        $audit = $f['order']->cancellationAudits()->where('scope', 'paid_order')->sole();
        $this->assertSame('Comanda incorrecta', $audit->cancellation_reason);
        $this->assertSame('Era la venta correcta', $audit->restoration_reason);
        $this->assertSame($f['actor']->id, $audit->restored_by);
        $this->assertNotNull($audit->restored_at);

        try {
            app(RestoreCancelledPaidOrderAction::class)->execute($f['order']->refresh(), $f['actor'], 'Segundo intento');
            $this->fail('La segunda restauración debía rechazarse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ya fue restaurado', $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', count($amounts) * 3);

        $this->actingInContext($f)->get(route('orders.index'))
            ->assertOk()
            ->assertSee('ANULADO')
            ->assertSee('ANULACIÓN REVERTIDA')
            ->assertSee('Comanda incorrecta')
            ->assertSee('Era la venta correcta');
    }

    public function test_paid_restore_does_not_restore_items_cancelled_before_the_full_cancellation(): void
    {
        $f = $this->fixture();
        $previouslyCancelled = OrderItem::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'order_id' => $f['order']->id,
            'quantity' => '1.000',
            'unit_price' => '3.00',
            'line_total' => '3.00',
            'fulfillment_type' => $f['order']->type,
            'requires_preparation' => false,
            'status' => 'cancelled',
            'configuration_snapshot' => ['type' => 'standalone_extra', 'extra' => ['name' => 'Previamente anulado']],
            'created_by' => $f['actor']->id,
            'cancelled_at' => now()->subMinute(),
            'cancelled_by' => $f['actor']->id,
            'cancellation_reason' => 'Antes del pago',
        ]);

        app(CancelPaidOrderAction::class)->execute($f['order'], $f['actor'], 'Anulación total');
        app(RestoreCancelledPaidOrderAction::class)->execute($f['order']->refresh(), $f['actor'], 'Restauración selectiva');

        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertSame(OrderItemStatus::Cancelled, $previouslyCancelled->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $f['item']->refresh()->status);
        $parent = $f['order']->cancellationAudits()->where('scope', 'paid_order')->sole();
        $this->assertFalse($parent->children()->where('order_item_id', $previouslyCancelled->id)->exists());
    }

    public function test_closed_cash_session_and_insufficient_stock_each_abort_the_entire_restore(): void
    {
        $closed = $this->fixture();
        app(CancelPaidOrderAction::class)->execute($closed['order'], $closed['actor'], 'Anulación');
        $expected = app(CashSessionSummaryService::class)->calculate($closed['session'])['expected_cash'];
        app(CloseCashSessionAction::class)->execute($closed['session'], $expected, $closed['actor']);
        try {
            app(RestoreCancelledPaidOrderAction::class)->execute($closed['order']->refresh(), $closed['actor'], 'Caja cerrada');
            $this->fail('La caja cerrada debía bloquear la restauración.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('caja original está cerrada', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::Cancelled, $closed['order']->refresh()->status);
        $this->assertDatabaseCount('payments', 2);

        $stock = $this->fixture(amounts: ['qr' => '10.00']);
        app(CancelPaidOrderAction::class)->execute($stock['order'], $stock['actor'], 'Anulación');
        app(ApplyInventoryMovementAction::class)->execute(
            $stock['company'],
            $stock['branch'],
            $stock['inventory'],
            InventoryMovementType::AdjustmentOut,
            '95.000',
            null,
            $stock['actor'],
            reason: 'Consumo posterior',
        );
        $paymentCount = Payment::query()->where('order_id', $stock['order']->id)->count();
        try {
            app(RestoreCancelledPaidOrderAction::class)->execute($stock['order']->refresh(), $stock['actor'], 'Sin stock');
            $this->fail('El stock insuficiente debía abortar la restauración.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Stock insuficiente', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::Cancelled, $stock['order']->refresh()->status);
        $this->assertSame($paymentCount, Payment::query()->where('order_id', $stock['order']->id)->count());
        $this->assertSame('5.000', $stock['inventory']->inventoryStocks()->sole()->quantity);
    }

    public function test_legacy_cancellation_is_not_inferred_and_restore_http_is_strictly_authorized_and_tenant_scoped(): void
    {
        $legacy = $this->fixture();
        $legacy['order']->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Legacy'])->save();
        try {
            app(RestoreCancelledPaidOrderAction::class)->execute($legacy['order'], $legacy['actor'], 'No inferir');
            $this->fail('Una anulación legacy no debía restaurarse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('datos legacy', $exception->getMessage());
        }

        $f = $this->fixture();
        app(CancelPaidOrderAction::class)->execute($f['order'], $f['actor'], 'Error');
        $this->actingInContext($f)->post(route('orders.restore-paid', $f['order']->ulid), ['confirmed' => '1'])
            ->assertSessionHasErrors('reason');

        foreach ([MembershipRole::Cashier, MembershipRole::Kitchen] as $role) {
            $user = User::factory()->create();
            Membership::factory()->for($f['company'])->for($user)->create(['role' => $role]);
            $this->actingInContext([...$f, 'actor' => $user])
                ->post(route('orders.restore-paid', $f['order']->ulid), ['reason' => 'Sin permiso', 'confirmed' => '1'])
                ->assertForbidden();
        }

        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingInContext(['actor' => $otherOwner, 'company' => $otherCompany, 'branch' => $otherBranch])
            ->post(route('orders.restore-paid', $f['order']->ulid), ['reason' => 'Otra empresa', 'confirmed' => '1'])
            ->assertNotFound();

        $this->actingInContext($f)
            ->post(route('orders.restore-paid', $f['order']->ulid), ['reason' => 'Corrección owner', 'confirmed' => '1'])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHasNoErrors();

        $adminCase = $this->fixture();
        app(CancelPaidOrderAction::class)->execute($adminCase['order'], $adminCase['actor'], 'Error');
        $admin = User::factory()->create();
        Membership::factory()->for($adminCase['company'])->for($admin)->create(['role' => MembershipRole::Admin]);
        $this->actingInContext([...$adminCase, 'actor' => $admin])
            ->post(route('orders.restore-paid', $adminCase['order']->ulid), ['reason' => 'Corrección admin', 'confirmed' => '1'])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHasNoErrors();
    }

    private function fixture(MembershipRole $role = MembershipRole::Owner, array $amounts = ['cash' => '10.00'], bool $standalone = true): array
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $actor = User::factory()->create();
        Membership::factory()->for($company)->for($actor)->create(['role' => $role]);
        $unit = Unit::factory()->for($company)->create();
        $inventory = InventoryItem::factory()->for($unit)->create();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $actor, null, TableChargeMode::AtEnd);
        if ($standalone) {
            $extra = app(SaveToppingAction::class)->execute($company, [
                'name' => 'Tocino', 'price_delta' => '5.00', 'inventory_item_ulid' => $inventory->ulid,
                'default_quantity' => '10.000', 'sort_order' => 0, 'is_active' => true, 'size_rules' => [],
            ]);
        } else {
            $product = Product::factory()->for($company)->create(['type' => ProductType::Beverage]);
            $variant = ProductVariant::factory()->for($product)->create(['price' => '5.00', 'requires_preparation' => false]);
            $inventory->forceFill(['ingredient_id' => null, 'product_variant_id' => $variant->id])->save();
        }
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn, '100.000', '1.000000', $actor);
        $item = $standalone
            ? app(AddStandaloneExtraAction::class)->execute($order, $extra, '2.000', $actor)
            : app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $actor);
        app(DispatchOrderToKitchenAction::class)->execute($order, $actor);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '20.00', $actor);
        foreach ($amounts as $method => $amount) {
            app(RegisterPaymentAction::class)->execute($order->refresh(), $session, PaymentMethod::from($method), $amount, $actor, 'payment-'.$method);
        }
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);

        return compact('company', 'branch', 'actor', 'order', 'item', 'inventory', 'table', 'session');
    }

    private function actingInContext(array $f): static
    {
        return $this->actingAs($f['actor'])->withSession(['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id]);
    }
}
