<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\MarkKitchenItemReadyAction;
use App\Actions\MarkOrderItemServedAction;
use App\Actions\StartKitchenItemAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_dispatch_contains_only_new_lines_and_double_click_is_idempotent(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza Primavera');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $firstItem = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);

        $firstDispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertNotNull($firstDispatch);
        $this->assertSame(1, $firstDispatch->sequence_number);
        $this->assertSame([$firstItem->id], $firstDispatch->items->pluck('order_item_id')->all());
        $this->assertNull(app(DispatchOrderToKitchenAction::class)->execute($order, $owner));
        $this->assertDatabaseCount('kitchen_dispatches', 1);

        $secondItem = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $secondDispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertNotNull($secondDispatch);
        $this->assertSame(2, $secondDispatch->sequence_number);
        $this->assertSame([$secondItem->id], $secondDispatch->items->pluck('order_item_id')->all());
        $this->assertDatabaseCount('kitchen_dispatches', 2);
        $this->assertSame(OrderItemStatus::Sent, $firstItem->refresh()->status);
        $this->assertSame(OrderItemStatus::Sent, $secondItem->refresh()->status);
    }

    public function test_prepared_item_consumes_reserved_inventory_once_when_preparation_starts(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza Hawaiana');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertSame('10.000', $this->stockQuantity($branch, $inventoryItem));
        $this->assertSame(InventoryReservationStatus::Reserved, $item->reservations()->firstOrFail()->status);

        app(StartKitchenItemAction::class)->execute($item, $owner);
        app(StartKitchenItemAction::class)->execute($item->refresh(), $owner);

        $this->assertSame(OrderItemStatus::Preparing, $item->refresh()->status);
        $this->assertSame('8.000', $this->stockQuantity($branch, $inventoryItem));
        $this->assertSame(InventoryReservationStatus::Consumed, $item->reservations()->firstOrFail()->status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption)->count());

        app(MarkKitchenItemReadyAction::class)->execute($item, $owner);
        app(MarkOrderItemServedAction::class)->execute($item->refresh(), $owner);

        $this->assertSame(OrderItemStatus::Served, $item->refresh()->status);
        $this->assertNotNull($item->served_at);
    }

    public function test_direct_sale_is_ready_and_consumed_on_dispatch_but_never_appears_in_kds(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $branch, $owner, $unit);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);

        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertSame(OrderItemStatus::Ready, $item->refresh()->status);
        $this->assertSame('8.000', $this->stockQuantity($branch, $inventoryItem));
        $this->assertSame(InventoryReservationStatus::Consumed, $item->reservations()->firstOrFail()->status);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('kitchen.index'))
            ->assertOk()
            ->assertDontSee('Coca-Cola 500 ml')
            ->assertDontSee('Bs 12,00');
    }

    public function test_kds_shows_only_prepared_items_and_never_financial_information(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$pizza] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza Pepperoni');
        [$drink] = $this->directVariant($company, $branch, $owner, $unit);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        app(AddOrderItemAction::class)->execute($order, $pizza, '1.000', $owner, notes: 'Sin cebolla');
        app(AddOrderItemAction::class)->execute($order, $drink, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);

        $this->actingInContext($kitchen, $company, $branch)
            ->get(route('kitchen.index'))
            ->assertOk()
            ->assertSee('Pizza Pepperoni')
            ->assertSee('Sin cebolla')
            ->assertDontSee('Coca-Cola 500 ml')
            ->assertDontSee('Bs ');
    }

    public function test_cancelling_sent_item_releases_reservation_and_keeps_dispatch_history(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza cancelada');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        app(CancelOrderItemAction::class)->execute($item, $owner, 'Cliente cambió de opinión');

        $this->assertSame(OrderItemStatus::Cancelled, $item->refresh()->status);
        $this->assertSame('Cliente cambió de opinión', $item->cancellation_reason);
        $this->assertSame($owner->id, $item->cancelled_by);
        $this->assertSame(InventoryReservationStatus::Released, $item->reservations()->firstOrFail()->status);
        $this->assertDatabaseHas('kitchen_dispatch_items', [
            'kitchen_dispatch_id' => $dispatch->id,
            'order_item_id' => $item->id,
        ]);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_cancelling_preparing_item_preserves_consumption_and_requires_cancel_permission(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza en horno');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        try {
            app(CancelOrderItemAction::class)->execute($item, $waiter, 'Sin autorización');
            $this->fail('The waiter must not cancel a sent line.');
        } catch (AuthorizationException) {
            $this->assertSame(OrderItemStatus::Sent, $item->refresh()->status);
        }

        app(StartKitchenItemAction::class)->execute($item, $owner);
        app(CancelOrderItemAction::class)->execute($item->refresh(), $owner, 'Error confirmado');

        $this->assertSame(OrderItemStatus::Cancelled, $item->refresh()->status);
        $this->assertSame('9.000', $this->stockQuantity($branch, $inventoryItem));
        $this->assertSame(InventoryReservationStatus::Consumed, $item->reservations()->firstOrFail()->status);
    }

    public function test_kitchen_routes_reject_other_company_and_other_branch_resources(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza aislada');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $otherBranch = Branch::factory()->for($company)->create();
        $this->actingInContext($owner, $company, $otherBranch)
            ->post(route('kitchen.items.start', $item->ulid))
            ->assertNotFound();

        $otherCompany = Company::factory()->create();
        $otherCompanyBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingInContext($otherOwner, $otherCompany, $otherCompanyBranch)
            ->post(route('kitchen.items.start', $item->ulid))
            ->assertNotFound();
    }

    public function test_waiter_cannot_manage_kitchen_and_kitchen_cannot_open_financial_order_screen(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant] = $this->preparedVariant($company, $branch, $owner, $unit, 'Pizza roles');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $this->actingInContext($waiter, $company, $branch)
            ->post(route('kitchen.items.start', $item->ulid))
            ->assertForbidden();
        $this->actingInContext($waiter, $company, $branch)
            ->post(route('orders.cancel', $order->ulid))
            ->assertForbidden();

        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingInContext($kitchen, $company, $branch)
            ->get(route('orders.show', $order->ulid))
            ->assertForbidden();
    }

    public function test_reservations_reduce_pos_availability_before_physical_consumption(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $branch, $owner, $unit);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $stock = InventoryStock::query()->where('inventory_item_id', $inventoryItem->id)->firstOrFail();

        app(AddOrderItemAction::class)->execute($order, $variant, '3.000', $owner);
        $availability = app(InventoryAvailabilityService::class)->forStock($stock->refresh());

        $this->assertSame('10.000', $stock->quantity);
        $this->assertSame('3.000', $availability->reservedQuantity);
        $this->assertSame('7.000', $availability->availableQuantity);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad',
            'symbol' => 'u',
            'type' => UnitType::Unit,
        ]);

        return [$company, $branch, $owner, $unit];
    }

    private function preparedVariant(Company $company, Branch $branch, User $owner, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => "Ingrediente {$name}"]);
        $inventoryItem = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'name' => $ingredient->name,
            'is_active' => true,
        ]);
        $this->stock($company, $branch, $inventoryItem, $owner, '10.000');
        $product = Product::factory()->for($company)->create(['name' => $name, 'type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create([
            'name' => 'Familiar',
            'price' => '79.00',
            'requires_preparation' => true,
        ]);
        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $ingredient->id, 'quantity' => '1.000'],
        ], "Receta {$name}");

        return [$variant, $inventoryItem];
    }

    private function directVariant(Company $company, Branch $branch, User $owner, Unit $unit): array
    {
        $product = Product::factory()->for($company)->create([
            'name' => 'Coca-Cola',
            'type' => ProductType::Beverage,
        ]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create([
            'name' => '500 ml',
            'price' => '12.00',
            'requires_preparation' => false,
        ]);
        $inventoryItem = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'product_variant_id' => $variant->id,
            'name' => 'Coca-Cola 500 ml',
            'is_active' => true,
        ]);
        $this->stock($company, $branch, $inventoryItem, $owner, '10.000');

        return [$variant, $inventoryItem];
    }

    private function stock(Company $company, Branch $branch, InventoryItem $item, User $owner, string $quantity): void
    {
        app(ApplyInventoryMovementAction::class)->execute(
            $company,
            $branch,
            $item,
            InventoryMovementType::AdjustmentIn,
            $quantity,
            '1.000000',
            $owner,
            reason: 'Stock de prueba',
        );
    }

    private function stockQuantity(Branch $branch, InventoryItem $item): string
    {
        return InventoryStock::query()
            ->where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)
            ->value('quantity');
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
