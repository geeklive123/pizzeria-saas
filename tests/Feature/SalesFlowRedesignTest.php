<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\FinalizePerBatchTableAction;
use App\Actions\MarkOrderReadyForPaymentAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\RegisterMixedPaymentAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderFinancialService;
use App\Services\OrderPaymentService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesFlowRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_each_table_account_snapshots_its_selected_charge_mode_independently(): void
    {
        [$company, $branch, $owner] = $this->context(TableChargeMode::AtEnd);
        $perBatchTable = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $atEndTable = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $perBatch = app(OpenTableOrderAction::class)->execute($company, $branch, $perBatchTable, $owner, 'Carlos', TableChargeMode::PerBatch);
        $atEnd = app(OpenTableOrderAction::class)->execute($company, $branch, $atEndTable, $owner, null, TableChargeMode::AtEnd);

        $this->assertSame(TableChargeMode::PerBatch, $perBatch->charge_mode);
        $this->assertSame(TableChargeMode::AtEnd, $atEnd->charge_mode);
        $this->assertSame('Carlos', $perBatch->customer_name);
        $this->assertNull($atEnd->customer_name);

        $company->update(['table_charge_mode' => TableChargeMode::PerBatch]);
        $this->assertSame(TableChargeMode::PerBatch, $perBatch->refresh()->charge_mode);
        $this->assertSame(TableChargeMode::AtEnd, $atEnd->refresh()->charge_mode);

        $thirdTable = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $third = app(OpenTableOrderAction::class)->execute($company->refresh(), $branch, $thirdTable, $owner, null, TableChargeMode::AtEnd);
        $this->assertSame(TableChargeMode::AtEnd, $third->charge_mode);
    }

    public function test_per_batch_mixed_payments_release_only_their_lines_and_finalizing_does_not_charge_again(): void
    {
        [$company, $branch, $owner, $register, $unit] = $this->context(TableChargeMode::PerBatch);
        [$variant, $inventory] = $this->directVariant($company, $branch, $owner, $unit);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner, null, TableChargeMode::PerBatch);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);

        $firstItem = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);
        $first = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        $this->assertSame(KitchenDispatchStatus::AwaitingPayment, $first->status);
        $this->assertSame(OrderItemStatus::PendingPayment, $firstItem->refresh()->status);
        $this->assertSame('10.000', $this->stock($branch, $inventory));

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '10.00', $owner, 'batch-cash', '10.00', dispatch: $first);
        app(RegisterPaymentAction::class)->execute($order->refresh(), $session, PaymentMethod::Qr, '14.00', $owner, 'batch-qr', reference: 'QR-1', dispatch: $first->refresh());
        $this->assertSame(KitchenDispatchStatus::Settled, $first->refresh()->status);
        $this->assertSame('8.000', $this->stock($branch, $inventory));
        $this->assertSame(OrderStatus::Open, $order->refresh()->status);
        $this->assertSame($table->id, $order->active_restaurant_table_id);

        $secondItem = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $second = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        app(RegisterPaymentAction::class)->execute($order->refresh(), $session, PaymentMethod::Qr, '12.00', $owner, 'batch-two', dispatch: $second);
        $this->assertSame('7.000', $this->stock($branch, $inventory));
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption)->count());
        $this->assertSame(InventoryReservationStatus::Consumed, $secondItem->reservations()->first()->status);

        $paymentCount = $order->payments()->count();
        app(FinalizePerBatchTableAction::class)->execute($order->refresh(), $owner);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertSame($paymentCount, $order->payments()->count());
    }

    public function test_per_batch_quick_cash_and_qr_use_the_full_balance_without_manual_amounts(): void
    {
        [$company, $branch, $owner, $session, $order, $dispatch] = $this->paymentBatch('38.00');

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('min-[1180px]:sticky')
            ->assertSee('data-quick-payment', false)
            ->assertDontSee('CONFIRMAR EFECTIVO')
            ->assertDontSee('CONFIRMAR QR')
            ->assertSee('CONFIRMAR PAGO MIXTO');

        $dispatchCount = $order->kitchenDispatches()->count();
        $cashPayload = [
            'method' => PaymentMethod::Cash->value,
            'amount' => '38.00',
            'idempotency_key' => 'quick-cash-full',
            'kitchen_dispatch' => $dispatch->ulid,
        ];
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('orders.payments.store', $order->ulid), $cashPayload)
            ->assertRedirect(route('orders.show', $order->ulid));
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('orders.payments.store', $order->ulid), $cashPayload)
            ->assertRedirect(route('orders.show', $order->ulid));

        $cash = Payment::query()->where('kitchen_dispatch_id', $dispatch->id)->sole();
        $this->assertSame('38.00', $cash->amount);
        $this->assertSame('38.00', $cash->received_amount);
        $this->assertSame('0.00', $cash->change_amount);
        $this->assertSame(KitchenDispatchStatus::Settled, $dispatch->refresh()->status);
        $this->assertSame(OrderStatus::Open, $order->refresh()->status);
        $this->assertSame($session->id, $cash->cash_session_id);
        $this->assertSame('0.00', app(OrderPaymentService::class)->dispatchBalance($dispatch->refresh()));
        $this->assertSame($dispatchCount, $order->kitchenDispatches()->count());

        [$company, $branch, $owner, , $order, $dispatch] = $this->paymentBatch('38.00');
        $dispatchCount = $order->kitchenDispatches()->count();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('orders.payments.store', $order->ulid), [
                'method' => PaymentMethod::Qr->value,
                'amount' => '38.00',
                'idempotency_key' => 'quick-qr-full',
                'kitchen_dispatch' => $dispatch->ulid,
            ])->assertRedirect(route('orders.show', $order->ulid));

        $this->assertDatabaseHas('payments', [
            'kitchen_dispatch_id' => $dispatch->id,
            'method' => PaymentMethod::Qr->value,
            'amount' => 38,
        ]);
        $this->assertSame('0.00', app(OrderPaymentService::class)->dispatchBalance($dispatch->refresh()));
        $this->assertSame(OrderStatus::Open, $order->refresh()->status);
        $this->assertSame($dispatchCount, $order->kitchenDispatches()->count());
    }

    public function test_quick_cash_with_more_received_keeps_the_payment_at_balance_and_records_change(): void
    {
        [$company, $branch, $owner, , $order, $dispatch] = $this->paymentBatch('38.00');

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('orders.payments.store', $order->ulid), [
                'method' => PaymentMethod::Cash->value,
                'amount' => '38.00',
                'received_amount' => '50.00',
                'idempotency_key' => 'quick-cash-change',
                'kitchen_dispatch' => $dispatch->ulid,
            ])->assertRedirect(route('orders.show', $order->ulid));

        $payment = Payment::query()->where('kitchen_dispatch_id', $dispatch->id)->sole();
        $this->assertSame('38.00', $payment->amount);
        $this->assertSame('50.00', $payment->received_amount);
        $this->assertSame('12.00', $payment->change_amount);
    }

    public function test_atomic_mixed_payment_calculates_qr_rejects_invalid_cash_and_is_idempotent(): void
    {
        [, , $owner, $session, $order, $dispatch, $variant] = $this->paymentBatch('38.00');
        $action = app(RegisterMixedPaymentAction::class);

        foreach (['38.01', '-0.01'] as $invalidCash) {
            try {
                $action->execute($order->refresh(), $session, $invalidCash, $owner, 'invalid-'.$invalidCash, dispatch: $dispatch->refresh());
                $this->fail('El efectivo inválido debió rechazarse.');
            } catch (DomainException) {
                $this->assertDatabaseCount('payments', 0);
            }
        }

        $first = $action->execute($order->refresh(), $session, '20.00', $owner, 'mixed-38-20', '25.00', dispatch: $dispatch->refresh());
        $retry = $action->execute($order->refresh(), $session, '20.00', $owner, 'mixed-38-20', '25.00', dispatch: $dispatch->refresh());

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['kitchen_dispatch_id' => $dispatch->id, 'method' => 'cash', 'amount' => 20, 'received_amount' => 25, 'change_amount' => 5]);
        $this->assertDatabaseHas('payments', ['kitchen_dispatch_id' => $dispatch->id, 'method' => 'qr', 'amount' => 18]);
        $this->assertSame(OrderStatus::Open, $order->refresh()->status);
        $this->assertNotNull($order->active_restaurant_table_id);

        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $next = app(DispatchOrderToKitchenAction::class)->execute($order->refresh(), $owner);
        $this->assertSame(2, $next->sequence_number);
        $this->assertSame(KitchenDispatchStatus::AwaitingPayment, $next->status);

        [, , $owner, $session, $order, $dispatch] = $this->paymentBatch('100.00');
        app(RegisterMixedPaymentAction::class)->execute($order, $session, '60.00', $owner, 'mixed-100-60', dispatch: $dispatch);
        $this->assertDatabaseHas('payments', ['kitchen_dispatch_id' => $dispatch->id, 'method' => 'cash', 'amount' => 60]);
        $this->assertDatabaseHas('payments', ['kitchen_dispatch_id' => $dispatch->id, 'method' => 'qr', 'amount' => 40]);
    }

    public function test_mixed_payment_rolls_back_cash_when_qr_fails(): void
    {
        [, , $owner, $session, $order, $dispatch] = $this->paymentBatch('38.00');
        DB::unprepared("CREATE TEMP TRIGGER fail_mixed_qr BEFORE INSERT ON payments WHEN NEW.method = 'qr' BEGIN SELECT RAISE(ABORT, 'forced qr failure'); END");

        try {
            app(RegisterMixedPaymentAction::class)->execute($order, $session, '20.00', $owner, 'mixed-atomic', dispatch: $dispatch);
            $this->fail('El QR forzado debía fallar.');
        } catch (QueryException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseMissing('cash_movements', ['type' => 'sale_cash']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_mixed_qr');
        }
    }

    public function test_at_end_releases_multiple_batches_and_closes_without_kitchen_interaction(): void
    {
        [$company, $branch, $owner, $register, $unit] = $this->context(TableChargeMode::AtEnd);
        [$variant, $inventory] = $this->directVariant($company, $branch, $owner, $unit);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner, 'Andrea');

        $firstItem = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $first = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        $secondItem = app(AddOrderItemAction::class)->execute($order->refresh(), $variant, '2.000', $owner);
        $second = app(DispatchOrderToKitchenAction::class)->execute($order->refresh(), $owner);

        $this->assertSame([$firstItem->id], $first->items->pluck('order_item_id')->all());
        $this->assertSame([$secondItem->id], $second->items->pluck('order_item_id')->all());
        $this->assertSame('7.000', $this->stock($branch, $inventory));
        $this->assertSame(OrderItemStatus::Ready, $firstItem->refresh()->status);
        app(MarkOrderReadyForPaymentAction::class)->execute($order->refresh(), $owner);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        app(RegisterPaymentAction::class)->execute($order->refresh(), $session, PaymentMethod::Qr, '36.00', $owner, 'end-qr');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertSame(OrderItemStatus::Ready, $firstItem->refresh()->status);
    }

    public function test_takeaway_waits_for_payment_then_consumes_and_finishes(): void
    {
        [$company, $branch, $owner, $register, $unit] = $this->context(TableChargeMode::AtEnd);
        [$variant, $inventory] = $this->directVariant($company, $branch, $owner, $unit);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner, ['customer_name' => 'Daniela']);
        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        $this->assertSame('10.000', $this->stock($branch, $inventory));

        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '12.00', $owner, 'takeaway', '20.00', dispatch: $dispatch);

        $this->assertSame('9.000', $this->stock($branch, $inventory));
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame('Daniela', $order->customer_name);
    }

    public function test_takeaway_closes_when_a_later_draft_item_is_cancelled_after_the_dispatch_was_fully_paid(): void
    {
        [$company, $branch, $owner, $register, $unit] = $this->context(TableChargeMode::AtEnd);
        [$variant] = $this->directVariant($company, $branch, $owner, $unit);
        $variant->forceFill(['price' => '112.00'])->save();
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner, ['customer_name' => 'Produccion']);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);

        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $variant->forceFill(['price' => '13.00'])->save();
        $cancelledItem = app(AddOrderItemAction::class)->execute($order->refresh(), $variant, '1.000', $owner);

        $this->assertSame('125.00', $order->refresh()->total);
        app(RegisterPaymentAction::class)->execute(
            $order,
            $session,
            PaymentMethod::Qr,
            '112.00',
            $owner,
            'paid-before-later-cancellation',
            dispatch: $dispatch,
        );

        $this->assertSame(KitchenDispatchStatus::Settled, $dispatch->refresh()->status);
        $this->assertSame(OrderStatus::Open, $order->refresh()->status);

        app(CancelOrderItemAction::class)->execute($cancelledItem->refresh(), $owner);

        $this->assertSame('112.00', $order->refresh()->total);
        $this->assertSame('0.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->closed_at);

        $response = $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('orders.index'))
            ->assertOk();
        $this->assertFalse($response->viewData('orders')->contains('id', $order->id));
    }

    public function test_discount_uses_only_pizza_base_and_server_ignores_client_totals(): void
    {
        [$company, $branch, $owner] = $this->context(TableChargeMode::PerBatch);
        $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'type' => OrderType::Takeaway, 'charge_mode' => TableChargeMode::PerBatch, 'created_by' => $owner->id]);
        $pizza = $this->financialItem($order, $company, $owner, '90.00', '100.00', true);
        $this->financialItem($order, $company, $owner, '20.00', '20.00', false);
        $preview = app(OrderFinancialService::class)->preview($order->items()->with('sections')->get(), '25');

        $this->assertSame('90.00', $preview['pizza_base']);
        $this->assertSame('10.00', $preview['extras']);
        $this->assertSame('20.00', $preview['other']);
        $this->assertSame('22.50', $preview['discount']);
        $this->assertSame('97.50', $preview['total']);
        $this->assertSame('22.50', $preview['lines'][$pizza->id]['discount']);
        $this->assertSame('77.50', $preview['lines'][$pizza->id]['net']);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('orders.dispatch', $order->ulid), ['discount_percentage' => '25', 'subtotal' => '1.00', 'discount_total' => '99.00', 'total' => '1.00'])
            ->assertRedirect();
        $dispatch = $order->kitchenDispatches()->firstOrFail();
        $this->assertSame('90.00', $dispatch->pizza_base_subtotal);
        $this->assertSame('22.50', $dispatch->discount_total);
        $this->assertSame('97.50', $dispatch->total);
        $this->assertSame(OrderItemStatus::PendingPayment, $pizza->refresh()->status);

        $threshold = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $this->financialItem($threshold, $company, $owner, '80.00', '80.00', true);
        $this->assertFalse(app(OrderFinancialService::class)->preview($threshold->items()->with('sections')->get())['eligible']);
        $above = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $this->financialItem($above, $company, $owner, '80.01', '80.01', true);
        $this->assertTrue(app(OrderFinancialService::class)->preview($above->items()->with('sections')->get())['eligible']);

        $twoPizzas = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $this->financialItem($twoPizzas, $company, $owner, '45.00', '45.00', true);
        $this->financialItem($twoPizzas, $company, $owner, '45.00', '45.00', true);
        $this->assertTrue(app(OrderFinancialService::class)->preview($twoPizzas->items()->with('sections')->get())['eligible']);

        $example = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $this->financialItem($example, $company, $owner, '87.00', '92.00', true);
        $this->financialItem($example, $company, $owner, '10.00', '10.00', false);
        $examplePreview = app(OrderFinancialService::class)->preview($example->items()->with('sections')->get(), '10');
        $this->assertSame('87.00', $examplePreview['pizza_base']);
        $this->assertSame('5.00', $examplePreview['extras']);
        $this->assertSame('8.70', $examplePreview['discount']);
        $this->assertSame('93.30', $examplePreview['total']);

        $extrasOnly = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $owner->id]);
        $this->financialItem($extrasOnly, $company, $owner, '50.00', '90.00', true);
        $this->assertFalse(app(OrderFinancialService::class)->preview($extrasOnly->items()->with('sections')->get())['eligible']);
        $this->expectException(DomainException::class);
        app(OrderFinancialService::class)->preview($extrasOnly->items()->with('sections')->get(), '25');
    }

    private function context(TableChargeMode $mode): array
    {
        $company = Company::factory()->create(['table_charge_mode' => $mode]);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $unit = Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);

        return [$company, $branch, $owner, $register, $unit];
    }

    private function directVariant(Company $company, Branch $branch, User $owner, Unit $unit): array
    {
        $product = Product::factory()->for($company)->create(['name' => 'Bebida', 'type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['name' => 'Unidad', 'price' => '12.00', 'requires_preparation' => false]);
        $inventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $variant->id, 'name' => 'Bebida', 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn, '10.000', '1.000000', $owner, reason: 'Prueba');

        return [$variant, $inventory];
    }

    private function paymentBatch(string $total): array
    {
        [$company, $branch, $owner, $register, $unit] = $this->context(TableChargeMode::PerBatch);
        [$variant] = $this->directVariant($company, $branch, $owner, $unit);
        $variant->forceFill(['price' => $total])->save();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner, null, TableChargeMode::PerBatch);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        return [$company, $branch, $owner, $session, $order, $dispatch, $variant];
    }

    private function financialItem(Order $order, Company $company, User $owner, string $base, string $gross, bool $pizza): OrderItem
    {
        $product = Product::factory()->for($company)->create(['type' => $pizza ? ProductType::Pizza : ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['price' => $base]);
        $item = OrderItem::factory()->for($order)->create(['company_id' => $company->id, 'branch_id' => $order->branch_id, 'product_variant_id' => $variant->id, 'unit_price' => $gross, 'line_total' => $gross, 'created_by' => $owner->id]);
        if ($pizza) {
            $item->sections()->create(['company_id' => $company->id, 'branch_id' => $order->branch_id, 'product_variant_id' => $variant->id, 'fraction_numerator' => 1, 'fraction_denominator' => 1, 'position' => 1, 'unit_price_snapshot' => $base, 'product_name_snapshot' => $product->name, 'variant_name_snapshot' => $variant->name]);
        }

        return $item;
    }

    private function stock(Branch $branch, InventoryItem $item): string
    {
        return InventoryStock::query()->where('branch_id', $branch->id)->where('inventory_item_id', $item->id)->value('quantity');
    }
}
