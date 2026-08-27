<?php

namespace Tests\Feature;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\SaveToppingAction;
use App\Actions\StartKitchenItemAction;
use App\Actions\UpdateConfiguredPizzaAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Membership;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderPosCatalogService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToppingSalesFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_pizza_without_toppings_keeps_historical_price_and_flow(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [$f['a']]);

        $this->assertSame('75.00', $item->unit_price);
        $this->assertSame('75.00', $item->line_total);
        $this->assertTrue($item->modifiers->isEmpty());
        $this->assertArrayNotHasKey('toppings', $item->configuration_snapshot);
    }

    public function test_one_and_multiple_toppings_use_server_prices_and_price_only_topping_creates_no_inventory(): void
    {
        $f = $this->fixture();
        $priceOnly = $this->topping($f, 'Aceitunas', '3.25');
        [$baconItem] = $this->toppingInventory($f, 'Tocino', '100.000');
        $bacon = $this->topping($f, 'Tocino', '5.00', $baconItem, '20.000');

        $one = $this->add($f, [$f['a']], [$priceOnly]);
        $this->assertSame('78.25', $one->unit_price);
        $this->assertNull($one->modifiers->first()->inventory_item_id);
        $this->assertDatabaseMissing('inventory_reservations', [
            'order_item_id' => $one->id,
            'inventory_item_id' => $baconItem->id,
        ]);

        $many = $this->add($f, [$f['b']], [$priceOnly, $bacon]);
        $this->assertSame('88.25', $many->unit_price);
        $this->assertCount(2, $many->modifiers);
        $this->assertReservation($many, $baconItem, '20.000');
    }

    public function test_size_rule_overrides_general_price_and_quantity_and_snapshot_is_historical(): void
    {
        $f = $this->fixture();
        [$item] = $this->toppingInventory($f, 'Champiñones', '100.000');
        $topping = $this->topping($f, 'Champiñones', '4.00', $item, '10.000', [
            ['size_key' => 'familiar', 'price_delta' => '6.50', 'quantity' => '30.000'],
        ]);

        $orderItem = $this->add($f, [$f['a']], [$topping]);
        $snapshot = $orderItem->configuration_snapshot['modifiers'][0];
        $this->assertSame('81.50', $orderItem->unit_price);
        $this->assertReservation($orderItem, $item, '30.000');
        $this->assertSame('Champiñones', $snapshot['name']);
        $this->assertSame('6.50', $snapshot['price_delta']);
        $this->assertSame('30.000', $snapshot['quantity']);
        $this->assertSame('familiar', $snapshot['size_key']);
        $this->assertSame('size_rule', $snapshot['price_source']);

        $topping->update(['name' => 'Champiñones premium', 'price_delta' => '20.00', 'quantity' => '50.000']);
        $topping->sizeRules()->first()->update(['price_delta' => '25.00', 'quantity' => '60.000']);
        $orderItem->refresh();
        $this->assertSame('81.50', $orderItem->unit_price);
        $this->assertSame('Champiñones', $orderItem->modifiers->first()->name_snapshot);
        $this->assertSame('6.50', $orderItem->modifiers->first()->price_delta_snapshot);
        $this->assertSame('30.000', $orderItem->modifiers->first()->quantity_snapshot);
        $this->assertSame($snapshot, $orderItem->configuration_snapshot['modifiers'][0]);
    }

    public function test_client_price_manipulation_is_ignored_and_server_recalculates(): void
    {
        $f = $this->fixture();
        $topping = $this->topping($f, 'Extra queso', '6.00');

        $session = $this->actingAs($f['owner'])->withSession([
            'active_company_id' => $f['company']->id,
            'active_branch_id' => $f['branch']->id,
        ]);
        $session->get(route('orders.show', $f['order']->ulid))
            ->assertOk()->assertSee('Toppings / extras')->assertSee('Extra queso')
            ->assertSee('data-pizza-topping', false)->assertSee('data-pizza-base-price', false);

        $session->post(route('orders.items.store', $f['order']->ulid), [
            'sections' => [['variant' => $f['a']->ulid]],
            'quantity' => '1.000',
            'fulfillment_type' => OrderType::DineIn->value,
            'toppings' => [$topping->ulid],
            'price_delta' => '0.01',
            'topping_prices' => [$topping->ulid => '0.01'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('81.00', $f['order']->items()->firstOrFail()->unit_price);
        $session->get(route('orders.show', $f['order']->ulid))
            ->assertOk()->assertSee('Guardar cambios')->assertSee('Extra queso');
    }

    public function test_draft_edit_can_select_and_remove_topping_without_leaving_reserved_stock(): void
    {
        $f = $this->fixture();
        [$inventoryItem] = $this->toppingInventory($f, 'Jamón', '100.000');
        $topping = $this->topping($f, 'Jamón', '5.00', $inventoryItem, '15.000');
        $item = $this->add($f, [$f['a']]);

        $selected = app(UpdateConfiguredPizzaAction::class)->execute(
            $item,
            $this->sections([$f['a']]),
            '1.000',
            $f['owner'],
            OrderType::DineIn,
            toppings: [$topping],
        );
        $this->assertSame('80.00', $selected->unit_price);
        $this->assertReservation($selected, $inventoryItem, '15.000');

        $removed = app(UpdateConfiguredPizzaAction::class)->execute(
            $selected,
            $this->sections([$f['a']]),
            '1.000',
            $f['owner'],
            OrderType::DineIn,
            toppings: [],
        );
        $this->assertSame('75.00', $removed->unit_price);
        $this->assertTrue($removed->modifiers->isEmpty());
        $this->assertDatabaseMissing('inventory_reservations', [
            'order_item_id' => $item->id,
            'inventory_item_id' => $inventoryItem->id,
            'status' => InventoryReservationStatus::Reserved->value,
        ]);
    }

    public function test_topping_applies_once_to_two_and_four_flavor_pizzas(): void
    {
        $f = $this->fixture();
        [$inventoryItem] = $this->toppingInventory($f, 'Tocino', '100.000');
        $topping = $this->topping($f, 'Tocino', '5.00', $inventoryItem, '20.000');

        $two = $this->add($f, [$f['a'], $f['b']], [$topping]);
        $four = $this->add($f, [$f['a'], $f['b'], $f['c'], $f['d']], [$topping]);

        $this->assertSame('85.00', $two->unit_price);
        $this->assertSame('90.00', $four->unit_price);
        $this->assertReservation($two, $inventoryItem, '20.000');
        $this->assertReservation($four, $inventoryItem, '20.000');
        $this->assertNull($two->modifiers->first()->order_item_section_id);
        $this->assertNull($four->modifiers->first()->order_item_section_id);
    }

    public function test_insufficient_or_expired_topping_stock_rejects_atomically(): void
    {
        $f = $this->fixture();
        [$scarceItem] = $this->toppingInventory($f, 'Escaso', '5.000');
        $scarce = $this->topping($f, 'Escaso', '2.00', $scarceItem, '10.000');

        try {
            $this->add($f, [$f['a']], [$scarce]);
            $this->fail('El topping sin stock suficiente debía rechazarse.');
        } catch (InsufficientStockException $exception) {
            $this->assertStringContainsString('Escaso', $exception->getMessage());
        }
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);

        [$expiredItem] = $this->toppingInventory($f, 'Vencido', '20.000', now()->subDay());
        $expired = $this->topping($f, 'Vencido', '2.00', $expiredItem, '10.000');
        try {
            $this->add($f, [$f['a']], [$expired]);
            $this->fail('El lote vencido no debía estar disponible.');
        } catch (InsufficientStockException $exception) {
            $this->assertStringContainsString('Vencido', $exception->getMessage());
        }
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_reservation_consumption_fefo_and_repeated_start_do_not_double_consume(): void
    {
        $f = $this->fixture();
        [$inventoryItem] = $this->toppingInventory($f, 'Pepperoni', '6.000', now()->addDays(2));
        $this->stock($f, $inventoryItem, '10.000', now()->addDays(10));
        $topping = $this->topping($f, 'Pepperoni', '5.00', $inventoryItem, '8.000');
        $item = $this->add($f, [$f['a']], [$topping]);
        $this->assertReservation($item, $inventoryItem, '8.000');

        app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        app(StartKitchenItemAction::class)->execute($item->refresh(), $f['owner']);
        app(StartKitchenItemAction::class)->execute($item->refresh(), $f['owner']);

        $movement = InventoryMovement::query()->where('inventory_item_id', $inventoryItem->id)
            ->where('type', InventoryMovementType::OrderConsumption)->firstOrFail();
        $batches = InventoryBatch::query()->where('inventory_item_id', $inventoryItem->id)
            ->orderBy('expires_at')->get();
        $this->assertSame('0.000', $batches[0]->quantity_remaining);
        $this->assertSame('8.000', $batches[1]->quantity_remaining);
        $this->assertSame($batches[0]->id, $movement->metadata['batch_allocations'][0]['batch_id']);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $inventoryItem->id)
            ->where('type', InventoryMovementType::OrderConsumption)->count());
        $this->assertSame(InventoryReservationStatus::Consumed, $item->reservations()
            ->where('inventory_item_id', $inventoryItem->id)->firstOrFail()->status);
    }

    public function test_cancellation_releases_reserved_topping_and_preserves_already_consumed_topping(): void
    {
        $f = $this->fixture();
        [$inventoryItem] = $this->toppingInventory($f, 'Queso extra', '100.000');
        $topping = $this->topping($f, 'Queso extra', '5.00', $inventoryItem, '12.000');
        $sent = $this->add($f, [$f['a']], [$topping]);
        app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        app(CancelOrderItemAction::class)->execute($sent->refresh(), $f['owner'], 'Cliente cambió de opinión');
        $this->assertSame(InventoryReservationStatus::Released, $sent->reservations()
            ->where('inventory_item_id', $inventoryItem->id)->firstOrFail()->status);
        $this->assertDatabaseMissing('inventory_movements', [
            'inventory_item_id' => $inventoryItem->id,
            'type' => InventoryMovementType::OrderConsumption->value,
        ]);

        $secondOrder = app(CreateTakeawayOrderAction::class)->execute($f['company'], $f['branch'], $f['owner']);
        $f['order'] = $secondOrder;
        $preparing = $this->add($f, [$f['a']], [$topping]);
        app(DispatchOrderToKitchenAction::class)->execute($secondOrder, $f['owner']);
        app(StartKitchenItemAction::class)->execute($preparing->refresh(), $f['owner']);
        app(CancelOrderItemAction::class)->execute($preparing->refresh(), $f['owner'], 'Error confirmado');
        $this->assertSame(OrderItemStatus::Cancelled, $preparing->refresh()->status);
        $this->assertSame(InventoryReservationStatus::Consumed, $preparing->reservations()
            ->where('inventory_item_id', $inventoryItem->id)->firstOrFail()->status);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $inventoryItem->id)
            ->where('type', InventoryMovementType::OrderConsumption)->count());
    }

    public function test_inactive_and_foreign_toppings_are_hidden_and_rejected(): void
    {
        $f = $this->fixture();
        $active = $this->topping($f, 'Activo', '2.00');
        $inactive = $this->topping($f, 'Inactivo', '3.00');
        $inactive->update(['is_active' => false]);

        $catalog = app(OrderPosCatalogService::class)->forOrderScreen($f['company'], $f['branch']);
        $this->assertSame([$active->id], $catalog['toppingOptions']->pluck('id')->all());
        $this->expectException(DomainException::class);
        $this->add($f, [$f['a']], [$inactive]);
    }

    public function test_topping_from_another_company_is_rejected(): void
    {
        $f = $this->fixture();
        $otherCompany = Company::factory()->create();
        $foreign = app(SaveToppingAction::class)->execute($otherCompany, [
            'name' => 'Ajeno', 'description' => null, 'price_delta' => '9.00',
            'inventory_item_ulid' => null, 'default_quantity' => null,
            'sort_order' => 0, 'is_active' => true, 'size_rules' => [],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no está disponible');
        $this->add($f, [$f['a']], [$foreign]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);
        $grams = Unit::factory()->for($company)->create(['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight]);
        [$base, $baseItem] = $this->ingredient($company, $grams, 'Masa');
        $this->stock(compact('company', 'branch', 'owner'), $baseItem, '5000.000');

        $variants = [];
        foreach ([['a', 'Sabor A', '75.00'], ['b', 'Sabor B', '80.00'], ['c', 'Sabor C', '82.00'], ['d', 'Sabor D', '85.00']] as [$key, $name, $price]) {
            [$flavor, $flavorItem] = $this->ingredient($company, $grams, 'Ingrediente '.$name);
            $this->stock(compact('company', 'branch', 'owner'), $flavorItem, '1000.000');
            $variants[$key] = $this->flavor($company, $name, $price, $base, $flavor);
        }
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        return compact('company', 'branch', 'owner', 'grams', 'order') + $variants;
    }

    private function flavor(Company $company, string $name, string $price, Ingredient $base, Ingredient $flavor): ProductVariant
    {
        $product = Product::factory()->for($company)->create(['name' => $name, 'type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create([
            'name' => 'Familiar', 'size_key' => 'familiar', 'price' => $price,
        ]);
        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $base->id, 'component_type' => 'base', 'quantity' => '100.000'],
            ['ingredient_id' => $flavor->id, 'component_type' => 'topping', 'quantity' => '10.000'],
        ], 'Receta '.$name);

        return $variant;
    }

    private function ingredient(Company $company, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);
        $item = InventoryItem::query()->create([
            'company_id' => $company->id, 'unit_id' => $unit->id, 'ingredient_id' => $ingredient->id,
            'product_variant_id' => null, 'name' => $name, 'is_active' => true,
        ]);

        return [$ingredient, $item];
    }

    private function toppingInventory(array $f, string $name, string $quantity, $expiresAt = null): array
    {
        [$ingredient, $item] = $this->ingredient($f['company'], $f['grams'], $name);
        $this->stock($f, $item, $quantity, $expiresAt);

        return [$item, $ingredient];
    }

    private function stock(array $f, InventoryItem $item, string $quantity, $expiresAt = null): void
    {
        app(ApplyInventoryMovementAction::class)->execute(
            $f['company'], $f['branch'], $item, InventoryMovementType::AdjustmentIn,
            $quantity, '1.000000', $f['owner'], reason: 'Stock prueba',
            batch: ['expires_at' => $expiresAt],
        );
    }

    private function topping(array $f, string $name, string $price, ?InventoryItem $item = null, ?string $quantity = null, array $sizeRules = []): ModifierOption
    {
        return app(SaveToppingAction::class)->execute($f['company'], [
            'name' => $name, 'description' => null, 'price_delta' => $price,
            'inventory_item_ulid' => $item?->ulid, 'default_quantity' => $quantity,
            'sort_order' => 0, 'is_active' => true, 'size_rules' => $sizeRules,
        ]);
    }

    private function add(array $f, array $variants, array $toppings = [])
    {
        return app(AddConfiguredPizzaAction::class)->execute(
            $f['order'], $this->sections($variants), '1.000', $f['owner'], OrderType::DineIn,
            toppings: $toppings,
        );
    }

    private function sections(array $variants): array
    {
        return array_map(fn (ProductVariant $variant): array => ['product_variant' => $variant], $variants);
    }

    private function assertReservation($item, InventoryItem $inventoryItem, string $quantity): void
    {
        $this->assertSame($quantity, InventoryReservation::query()
            ->where('order_item_id', $item->id)->where('inventory_item_id', $inventoryItem->id)
            ->where('status', InventoryReservationStatus::Reserved->value)->value('quantity'));
    }
}
