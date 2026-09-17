<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelKitchenDispatchAction;
use App\Actions\CancelKitchenDispatchItemAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\RestoreCancelledKitchenDispatchAction;
use App\Actions\RestoreCancelledKitchenDispatchItemAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Membership;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportDateRangeService;
use App\Services\SalesReportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartialKitchenDispatchCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_single_item_cancellation_preserves_order_batches_history_and_reverses_only_its_inventory_once(): void
    {
        $f = $this->fixture();
        [$pizza, $pizzaStock] = $this->directVariant($f, 'Pizza Grande', '75.00');
        [$drink, $drinkStock] = $this->directVariant($f, 'Refresco', '13.00');
        $wrongPizza = app(AddOrderItemAction::class)->execute($f['order'], $pizza, '1.000', $f['owner']);
        $drinkItem = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        $first = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $correctPizza = app(AddOrderItemAction::class)->execute($f['order']->refresh(), $pizza, '1.000', $f['owner']);
        $second = app(DispatchOrderToKitchenAction::class)->execute($f['order']->refresh(), $f['owner']);

        app(CancelKitchenDispatchItemAction::class)->execute($wrongPizza, $f['owner'], 'Producto duplicado');

        $this->assertSame(OrderStatus::Open, $f['order']->refresh()->status);
        $this->assertSame(OrderItemStatus::Cancelled, $wrongPizza->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $drinkItem->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $correctPizza->refresh()->status);
        $this->assertSame('88.00', $f['order']->total);
        $this->assertSame('13.00', $first->refresh()->total);
        $this->assertSame('75.00', $second->refresh()->total);
        $this->assertSame('9.000', $this->stock($pizzaStock));
        $this->assertSame('9.000', $this->stock($drinkStock));
        $this->assertSame('Producto duplicado', $wrongPizza->cancellation_reason);
        $this->assertSame($f['owner']->id, $wrongPizza->cancelled_by);
        $this->assertNotNull($wrongPizza->cancelled_at);
        $this->assertDatabaseHas('order_items', ['id' => $wrongPizza->id, 'status' => 'cancelled']);
        $this->assertSame(1, InventoryMovement::query()->where('reversal_of_id', $this->consumption($wrongPizza)->id)->count());

        try {
            app(CancelKitchenDispatchItemAction::class)->execute($wrongPizza->refresh(), $f['owner'], 'Segundo intento');
            $this->fail('La segunda anulación debía rechazarse.');
        } catch (DomainException $exception) {
            $this->assertSame('Este producto ya fue anulado.', $exception->getMessage());
        }
        $this->assertSame('9.000', $this->stock($pizzaStock));
        $this->assertSame(1, InventoryMovement::query()->whereNotNull('reversal_of_id')->count());

        $this->actingInContext($f['owner'], $f['company'], $f['branch'])
            ->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('TANDA #1')
            ->assertSee('TANDA #2')
            ->assertSee('ANULADO')
            ->assertSee('Producto duplicado')
            ->assertSee('Precio original: Bs 75,00');

        $f['order']->forceFill(['status' => OrderStatus::Paid, 'closed_at' => now()])->save();
        $report = app(SalesReportService::class)->data(
            $f['company'],
            $f['branch'],
            app(ReportDateRangeService::class)->from(['preset' => 'today']),
        );
        $this->assertNotContains($wrongPizza->id, $report['recognized_item_ids']->all());
        $this->assertContains($drinkItem->id, $report['recognized_item_ids']->all());
        $this->assertContains($correctPizza->id, $report['recognized_item_ids']->all());
    }

    public function test_cancelling_dispatch_only_cancels_its_active_items_and_keeps_other_dispatch_active(): void
    {
        $f = $this->fixture();
        [$pizza] = $this->directVariant($f, 'Pizza', '75.00');
        [$drink] = $this->directVariant($f, 'Refresco', '13.00');
        $firstPizza = app(AddOrderItemAction::class)->execute($f['order'], $pizza, '1.000', $f['owner']);
        $firstDrink = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        $first = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $secondPizza = app(AddOrderItemAction::class)->execute($f['order']->refresh(), $pizza, '1.000', $f['owner']);
        $second = app(DispatchOrderToKitchenAction::class)->execute($f['order']->refresh(), $f['owner']);

        app(CancelKitchenDispatchAction::class)->execute($first, $f['owner'], 'Tanda duplicada');

        $this->assertSame(OrderItemStatus::Cancelled, $firstPizza->refresh()->status);
        $this->assertSame(OrderItemStatus::Cancelled, $firstDrink->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $secondPizza->refresh()->status);
        $this->assertSame('cancelled', $first->refresh()->status->value);
        $this->assertSame('Tanda duplicada', $first->cancellation_reason);
        $this->assertSame($f['owner']->id, $first->cancelled_by);
        $this->assertNotNull($first->cancelled_at);
        $this->assertSame('released', $second->refresh()->status->value);
        $this->assertSame('75.00', $f['order']->refresh()->total);
        $this->assertSame('0.00', $first->total);
        $this->assertSame('75.00', $second->total);

        $this->expectException(DomainException::class);
        app(CancelKitchenDispatchAction::class)->execute($first, $f['owner'], 'Repetida');
    }

    public function test_pending_batch_releases_reserved_inventory_without_creating_reversal(): void
    {
        $f = $this->fixture(TableChargeMode::PerBatch);
        [$drink, $stock] = $this->directVariant($f, 'Refresco reservado', '13.00');
        $item = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);

        $this->assertSame(InventoryReservationStatus::Reserved, $item->reservations()->sole()->status);
        $this->assertSame('10.000', $this->stock($stock));

        app(CancelKitchenDispatchItemAction::class)->execute($item, $f['owner'], 'Cliente corrigió producto');

        $this->assertSame(InventoryReservationStatus::Released, $item->reservations()->sole()->refresh()->status);
        $this->assertSame('10.000', $this->stock($stock));
        $this->assertSame(0, InventoryMovement::query()->where('type', InventoryMovementType::Reversal->value)->count());
    }

    public function test_paid_and_cancelled_orders_reject_partial_cancellation_without_changing_payments_or_cash_session(): void
    {
        $f = $this->fixture();
        [$drink] = $this->directVariant($f, 'Refresco pagado', '13.00');
        $item = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $register = CashRegister::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'name' => 'Caja', 'is_active' => true]);
        $cashSession = app(OpenCashSessionAction::class)->execute($register, '0.00', $f['owner']);
        app(RegisterPaymentAction::class)->execute($f['order']->refresh(), $cashSession, PaymentMethod::Qr, '13.00', $f['owner'], 'partial-cancel-paid');

        try {
            app(CancelKitchenDispatchItemAction::class)->execute($item, $f['owner'], 'No permitido');
            $this->fail('Un pedido pagado no debe admitir anulación parcial.');
        } catch (DomainException $exception) {
            $this->assertSame('Este pedido ya fue pagado. Para modificar productos debe utilizar un flujo de reversión/reembolso.', $exception->getMessage());
        }
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('13.00', Payment::query()->sole()->amount);
        $this->assertNotNull($cashSession->refresh()->active_cash_register_id);
        $this->assertSame(OrderItemStatus::Ready, $item->refresh()->status);
        $this->assertNotSame('cancelled', $dispatch->refresh()->status->value);

        $f['order']->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->expectException(DomainException::class);
        app(CancelKitchenDispatchItemAction::class)->execute($item, $f['owner'], 'No permitido');
    }

    public function test_owner_admin_and_cashier_are_authorized_but_kitchen_is_forbidden_and_company_is_isolated(): void
    {
        foreach ([MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Cashier] as $role) {
            $f = $this->fixture();
            [$drink] = $this->directVariant($f, 'Producto '.$role->value, '10.00');
            $item = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
            $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
            $actor = $role === MembershipRole::Owner ? $f['owner'] : User::factory()->create();
            if ($role !== MembershipRole::Owner) {
                Membership::factory()->for($f['company'])->for($actor)->create(['role' => $role]);
            }
            $this->actingInContext($actor, $f['company'], $f['branch'])
                ->post(route('orders.dispatches.items.cancel', [$f['order']->ulid, $dispatch->ulid, $item->ulid]), ['reason' => 'Autorizado', 'confirmed' => '1'])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $f = $this->fixture();
        [$drink] = $this->directVariant($f, 'Producto protegido', '10.00');
        $item = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $kitchen = User::factory()->create();
        Membership::factory()->for($f['company'])->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingInContext($kitchen, $f['company'], $f['branch'])
            ->post(route('orders.dispatches.items.cancel', [$f['order']->ulid, $dispatch->ulid, $item->ulid]), ['reason' => 'No autorizado', 'confirmed' => '1'])
            ->assertForbidden();

        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingInContext($otherOwner, $otherCompany, $otherBranch)
            ->post(route('orders.dispatches.items.cancel', [$f['order']->ulid, $dispatch->ulid, $item->ulid]), ['reason' => 'Otra empresa', 'confirmed' => '1'])
            ->assertNotFound();
        $this->assertSame(OrderItemStatus::Ready, $item->refresh()->status);
    }

    public function test_standalone_extra_reverses_all_inventory_linked_to_it_and_keeps_batches_consistent(): void
    {
        $f = $this->fixture();
        [, $stockItem] = $this->directVariant($f, 'Tocino extra', '5.00');
        [, $packagingItem] = $this->directVariant($f, 'Empaque extra', '1.00');
        $item = OrderItem::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'order_id' => $f['order']->id,
            'product_variant_id' => null,
            'quantity' => '2.000',
            'unit_price' => '5.00',
            'line_total' => '10.00',
            'fulfillment_type' => $f['order']->type,
            'requires_preparation' => false,
            'status' => OrderItemStatus::Ready,
            'configuration_snapshot' => ['type' => 'standalone_extra', 'extra' => ['name' => 'Tocino']],
            'created_by' => $f['owner']->id,
        ]);
        foreach ([$stockItem, $packagingItem] as $reservedItem) {
            InventoryReservation::query()->create([
                'company_id' => $f['company']->id,
                'branch_id' => $f['branch']->id,
                'order_id' => $f['order']->id,
                'order_item_id' => $item->id,
                'inventory_item_id' => $reservedItem->id,
                'quantity' => '1.000',
                'status' => InventoryReservationStatus::Consumed,
                'reserved_at' => now(),
                'consumed_at' => now(),
            ]);
        }
        $dispatch = KitchenDispatch::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'order_id' => $f['order']->id,
            'sequence_number' => 1,
            'status' => 'released',
            'dispatched_at' => now(),
            'dispatched_by' => $f['owner']->id,
            'gross_subtotal' => '10.00',
            'extras_subtotal' => '10.00',
            'total' => '10.00',
        ]);
        KitchenDispatchItem::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'kitchen_dispatch_id' => $dispatch->id,
            'order_item_id' => $item->id,
            'financial_type' => 'extra',
            'gross_total' => '10.00',
            'extras_total' => '10.00',
            'net_total' => '10.00',
        ]);
        $f['order']->forceFill(['subtotal' => '10.00', 'extras_subtotal' => '10.00', 'total' => '10.00'])->save();
        foreach ([$stockItem, $packagingItem] as $part => $consumedItem) {
            app(ApplyInventoryMovementAction::class)->execute(
                $f['company'],
                $f['branch'],
                $consumedItem,
                InventoryMovementType::OrderConsumption,
                '1.000',
                null,
                $f['owner'],
                reason: 'Consumo extra '.$part,
                referenceType: OrderItem::class,
                referenceId: $item->id,
            );
        }
        $this->assertSame('9.000', $this->stock($stockItem));
        $this->assertSame('9.000', $this->stock($packagingItem));

        app(CancelKitchenDispatchItemAction::class)->execute($item, $f['owner'], 'Extra equivocado');

        $this->assertSame(OrderItemStatus::Cancelled, $item->refresh()->status);
        $this->assertSame('10.000', $this->stock($stockItem));
        $this->assertSame('10.000', $this->stock($packagingItem));
        $this->assertSame(2, InventoryMovement::query()->whereNotNull('reversal_of_id')->count());
        $this->assertSame('10.000', $stockItem->inventoryBatches()->sole()->quantity_remaining);
        $this->assertSame('10.000', $packagingItem->inventoryBatches()->sole()->quantity_remaining);
        $this->assertTrue($item->reservations->every(fn ($reservation) => $reservation->status === InventoryReservationStatus::Consumed));
    }

    public function test_cancelled_dispatch_item_can_be_restored_once_with_inventory_and_history(): void
    {
        $f = $this->fixture();
        [$drink, $stock] = $this->directVariant($f, 'Refresco restaurable', '13.00');
        $item = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        app(CancelKitchenDispatchItemAction::class)->execute($item, $f['owner'], 'Número equivocado');

        app(RestoreCancelledKitchenDispatchItemAction::class)->execute($item->refresh(), $f['owner'], 'Era la comanda correcta');

        $this->assertSame(OrderItemStatus::Ready, $item->refresh()->status);
        $this->assertSame('13.00', $f['order']->refresh()->total);
        $this->assertSame('13.00', $dispatch->refresh()->total);
        $this->assertSame('9.000', $this->stock($stock));
        $this->assertSame(2, InventoryMovement::query()->where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $audit = $item->cancellationAudits()->sole();
        $this->assertSame('Número equivocado', $audit->cancellation_reason);
        $this->assertSame('Era la comanda correcta', $audit->restoration_reason);
        $this->assertSame($f['owner']->id, $audit->restored_by);
        $this->assertNotNull($audit->restored_at);

        try {
            app(RestoreCancelledKitchenDispatchItemAction::class)->execute($item->refresh(), $f['owner'], 'Segundo intento');
            $this->fail('La segunda restauración debía rechazarse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ya fue restaurado', $exception->getMessage());
        }
        $this->assertSame('9.000', $this->stock($stock));

        $this->actingInContext($f['owner'], $f['company'], $f['branch'])
            ->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('ANULACIÓN REVERTIDA')
            ->assertSee('Número equivocado')
            ->assertSee('Era la comanda correcta');
    }

    public function test_restoring_dispatch_only_restores_items_cancelled_by_that_dispatch(): void
    {
        $f = $this->fixture();
        [$pizza, $pizzaStock] = $this->directVariant($f, 'Pizza restaurable', '75.00');
        [$drink, $drinkStock] = $this->directVariant($f, 'Refresco previamente anulado', '13.00');
        $pizzaItem = app(AddOrderItemAction::class)->execute($f['order'], $pizza, '1.000', $f['owner']);
        $drinkItem = app(AddOrderItemAction::class)->execute($f['order'], $drink, '1.000', $f['owner']);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        app(CancelKitchenDispatchItemAction::class)->execute($drinkItem, $f['owner'], 'Anulación previa');
        app(CancelKitchenDispatchAction::class)->execute($dispatch->refresh(), $f['owner'], 'Tanda equivocada');

        app(RestoreCancelledKitchenDispatchAction::class)->execute($dispatch->refresh(), $f['owner'], 'Restaurar solo la tanda');

        $this->assertSame(OrderItemStatus::Ready, $pizzaItem->refresh()->status);
        $this->assertSame(OrderItemStatus::Cancelled, $drinkItem->refresh()->status);
        $this->assertSame(KitchenDispatchStatus::Released, $dispatch->refresh()->status);
        $this->assertSame('75.00', $f['order']->refresh()->total);
        $this->assertSame('9.000', $this->stock($pizzaStock));
        $this->assertSame('10.000', $this->stock($drinkStock));
        $this->assertNotNull($dispatch->cancellationAudits()->where('scope', 'kitchen_dispatch')->sole()->restored_at);
    }

    private function fixture(TableChargeMode $chargeMode = TableChargeMode::AtEnd): array
    {
        $company = Company::factory()->create(['table_charge_mode' => $chargeMode]);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner, chargeMode: $chargeMode);

        return compact('company', 'branch', 'owner', 'unit', 'order');
    }

    private function directVariant(array $f, string $name, string $price): array
    {
        $product = Product::factory()->for($f['company'])->create(['name' => $name, 'type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($f['company'])->for($product)->create(['name' => 'Unidad', 'price' => $price, 'requires_preparation' => false]);
        $inventoryItem = InventoryItem::query()->create(['company_id' => $f['company']->id, 'unit_id' => $f['unit']->id, 'product_variant_id' => $variant->id, 'name' => $name, 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($f['company'], $f['branch'], $inventoryItem, InventoryMovementType::AdjustmentIn, '10.000', '1.000000', $f['owner'], reason: 'Stock inicial');

        return [$variant, $inventoryItem];
    }

    private function stock(InventoryItem $item): string
    {
        return InventoryStock::query()->where('inventory_item_id', $item->id)->value('quantity');
    }

    private function consumption(OrderItem $item): InventoryMovement
    {
        return InventoryMovement::query()->where('reference_type', OrderItem::class)->where('reference_id', $item->id)
            ->where('type', InventoryMovementType::OrderConsumption->value)->sole();
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
