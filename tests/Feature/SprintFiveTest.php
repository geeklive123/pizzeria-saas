<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\UpdateOrderItemQuantityAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SprintFiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_are_isolated_by_company_and_branch_and_use_public_ulids(): void
    {
        [$company, $branch] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'Mesa 1']);
        RestaurantTable::factory()->for($otherBranch)->create(['company_id' => $company->id, 'name' => 'Mesa 1']);

        $this->assertNotEmpty($table->ulid);
        $this->assertCount(1, RestaurantTable::query()->forCompany($company)->forBranch($branch)->get());
        $this->assertDatabaseCount('restaurant_tables', 2);
    }

    public function test_opening_a_table_creates_one_open_order_and_reuses_it_on_a_second_entry(): void
    {
        [$company, $branch, $owner] = $this->context();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner);

        $this->assertSame(OrderType::DineIn, $order->type);
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame($table->id, $order->active_restaurant_table_id);
        $this->assertNotEmpty($order->ulid);

        $sameOrder = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner);

        $this->assertSame($order->id, $sameOrder->id);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_takeaway_order_has_no_table_and_numbering_is_incremental(): void
    {
        [$company, $branch, $owner] = $this->context();
        $first = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner, ['customer_name' => 'Ana']);
        $second = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        $this->assertNull($first->restaurant_table_id);
        $this->assertSame(OrderType::Takeaway, $first->type);
        $this->assertSame($first->order_number + 1, $second->order_number);
        $this->assertNull($first->operational_number);
        $this->assertSame('Sin comanda', $first->formattedNumber());
        $this->assertSame('Sin comanda', $first->formattedOperationalNumber());
    }

    public function test_direct_product_reserves_inventory_without_changing_physical_stock_and_snapshots_price(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $unit, '10.00');
        $this->stock($company, $branch, $inventoryItem, $owner, '10.000');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        $item = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);
        $variant->update(['price' => '15.00']);
        $availability = app(InventoryAvailabilityService::class)->forStock(
            InventoryStock::query()->where('inventory_item_id', $inventoryItem->id)->firstOrFail(),
        );

        $this->assertSame('10.00', $item->unit_price);
        $this->assertSame('20.00', $item->line_total);
        $this->assertSame(OrderType::Takeaway, $item->fulfillment_type);
        $this->assertSame('10.000', InventoryStock::query()->where('inventory_item_id', $inventoryItem->id)->value('quantity'));
        $this->assertSame('2.000', $availability->reservedQuantity);
        $this->assertSame('8.000', $availability->availableQuantity);
    }

    public function test_recipe_product_reserves_each_ingredient_and_allows_item_takeaway_on_dine_in_order(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$firstIngredient, $firstInventory] = $this->ingredient($company, $unit, 'Masa');
        [$secondIngredient, $secondInventory] = $this->ingredient($company, $unit, 'Queso');
        $this->stock($company, $branch, $firstInventory, $owner, '1000.000');
        $this->stock($company, $branch, $secondInventory, $owner, '500.000');
        $product = Product::factory()->for($company)->create(['type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['price' => '40.00']);
        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $firstIngredient->id, 'quantity' => '200.000'],
            ['ingredient_id' => $secondIngredient->id, 'quantity' => '100.000'],
        ], 'Pizza prueba');
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner);

        $item = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner, OrderType::Takeaway);

        $this->assertSame(OrderType::Takeaway, $item->fulfillment_type);
        $this->assertDatabaseHas('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $firstInventory->id, 'quantity' => 400]);
        $this->assertDatabaseHas('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $secondInventory->id, 'quantity' => 200]);
    }

    public function test_expired_stock_is_not_reservable_and_low_minimum_does_not_block_available_stock(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $unit, '5.00');
        $this->stock($company, $branch, $inventoryItem, $owner, '2.000', CarbonImmutable::now('America/La_Paz')->subDay());
        $this->stock($company, $branch, $inventoryItem, $owner, '1.000');
        InventoryStock::query()->where('inventory_item_id', $inventoryItem->id)->update(['minimum_quantity' => '10.000']);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $this->assertDatabaseHas('inventory_reservations', ['inventory_item_id' => $inventoryItem->id, 'quantity' => 1]);

        $this->expectException(InsufficientStockException::class);
        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
    }

    public function test_insufficient_recipe_inventory_rolls_back_item_and_all_reservations(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$firstIngredient, $firstInventory] = $this->ingredient($company, $unit, 'Masa');
        [$secondIngredient, $secondInventory] = $this->ingredient($company, $unit, 'Queso');
        $this->stock($company, $branch, $firstInventory, $owner, '1000.000');
        $this->stock($company, $branch, $secondInventory, $owner, '50.000');
        $product = Product::factory()->for($company)->create(['type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create();
        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $firstIngredient->id, 'quantity' => '200.000'],
            ['ingredient_id' => $secondIngredient->id, 'quantity' => '100.000'],
        ], 'Receta insuficiente');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        try {
            app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
            $this->fail('Reservation should fail.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertSame('0.00', $order->refresh()->total);
        }
    }

    public function test_quantity_changes_reserve_only_difference_and_release_on_decrease(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $unit, '10.00');
        $this->stock($company, $branch, $inventoryItem, $owner, '10.000');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);

        app(UpdateOrderItemQuantityAction::class)->execute($item, '3.000', $owner);
        $this->assertSame('3.000', InventoryReservation::query()->where('order_item_id', $item->id)->value('quantity'));
        app(UpdateOrderItemQuantityAction::class)->execute($item, '2.000', $owner);
        $this->assertSame('2.000', InventoryReservation::query()->where('order_item_id', $item->id)->value('quantity'));
        $this->assertSame('20.00', $order->refresh()->total);
    }

    public function test_cancelling_item_and_order_releases_reservations_without_inventory_movements(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $unit, '10.00');
        $this->stock($company, $branch, $inventoryItem, $owner, '10.000');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $first = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        $second = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);
        $movementCount = InventoryMovement::query()->count();

        app(CancelOrderItemAction::class)->execute($first, $owner);
        $this->assertSame(InventoryReservationStatus::Released, $first->reservations()->firstOrFail()->status);
        app(CancelOrderAction::class)->execute($order, $owner);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertSame(OrderItemStatus::Cancelled, $second->refresh()->status);
        $this->assertSame($movementCount, InventoryMovement::query()->count());
    }

    public function test_order_receives_multiple_later_additions_without_creating_another_order(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        [$variant, $inventoryItem] = $this->directVariant($company, $unit, '7.50');
        $this->stock($company, $branch, $inventoryItem, $owner, '20.000');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);

        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(2, $order->items);
        $this->assertSame('22.50', $order->refresh()->subtotal);
        $this->assertSame('22.50', $order->total);
    }

    public function test_order_and_table_policies_match_operational_roles(): void
    {
        [$company] = $this->context();
        foreach ([MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Cashier, MembershipRole::Waiter] as $role) {
            $user = User::factory()->create();
            Membership::factory()->for($company)->for($user)->create(['role' => $role]);
            app(CompanyContext::class)->setForUser($user, $company);
            $this->assertTrue(Gate::forUser($user)->allows('create', Order::class));
        }
        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        app(CompanyContext::class)->setForUser($kitchen, $company);
        $this->assertFalse(Gate::forUser($kitchen)->allows('create', Order::class));
    }

    public function test_demo_table_seeder_is_idempotent_and_creates_six_tables(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(6, RestaurantTable::query()->count());
        $this->assertDatabaseCount('orders', 0);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);

        return [$company, $branch, $owner, $unit];
    }

    private function directVariant(Company $company, Unit $unit, string $price): array
    {
        $product = Product::factory()->for($company)->create(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['price' => $price]);
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $variant->id, 'name' => $product->name, 'is_active' => true]);

        return [$variant, $item];
    }

    private function ingredient(Company $company, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'ingredient_id' => $ingredient->id, 'name' => $name, 'is_active' => true]);

        return [$ingredient, $item];
    }

    private function stock(Company $company, Branch $branch, InventoryItem $item, User $owner, string $quantity, ?CarbonImmutable $expiresAt = null): void
    {
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $item, InventoryMovementType::AdjustmentIn, $quantity, '1.000000', $owner, reason: 'Stock prueba', batch: ['expires_at' => $expiresAt]);
    }
}
