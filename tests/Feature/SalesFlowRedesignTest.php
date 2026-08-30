<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\FinalizePerBatchTableAction;
use App\Actions\MarkOrderReadyForPaymentAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OpenTableOrderAction;
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
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderFinancialService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
