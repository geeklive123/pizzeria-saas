<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelKitchenDispatchItemAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\ConfigureNaturalJuiceFlavorsAction;
use App\Actions\ConsumeInventoryReservationAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\SaveProductAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\RecipeComponentType;
use App\Enums\TableChargeMode;
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
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NaturalJuiceFlavorStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_configuration_creates_independent_flavors_and_preserves_historical_unique_variant(): void
    {
        $context = $this->context();
        $historicalId = $context['historical']->id;
        $historicalItemId = $context['historicalItem']->id;
        $recipeId = $context['recipe']->id;

        $result = app(ConfigureNaturalJuiceFlavorsAction::class)
            ->execute($context['company'], $context['branch'], $context['owner']);

        $this->assertSame(4, $result['variants_created']);
        $this->assertSame(4, $result['inventory_items_created']);
        $this->assertSame(4, $result['stock_loaded']);
        $this->assertSame('20.00', $result['price']);
        $historical = ProductVariant::query()->findOrFail($historicalId);
        $this->assertSame('Única', $historical->name);
        $this->assertFalse($historical->is_active);
        $this->assertSame('20.00', $historical->price);
        $this->assertSame($recipeId, $historical->recipe->id);
        $this->assertFalse($historical->recipe->is_active);
        $historicalItem = OrderItem::query()->findOrFail($historicalItemId);
        $this->assertSame($historicalId, $historicalItem->product_variant_id);
        $this->assertSame('Única', $historicalItem->productVariant->name);

        $active = $context['product']->variants()->where('is_active', true)
            ->orderBy('sort_order')->get();
        $this->assertSame(['Piña', 'Maracuyá', 'Tumbo', 'Copoazú'], $active->pluck('name')->all());
        $this->assertTrue($active->every(fn (ProductVariant $variant): bool => $variant->price === '20.00'));
        $this->assertTrue($active->every(fn (ProductVariant $variant): bool => $variant->recipe === null));
        $this->assertTrue($active->every(
            fn (ProductVariant $variant): bool => $variant->inventoryItem->ingredient_id === null
                && $variant->inventoryItem->unit_id === $context['unit']->id,
        ));
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
        $this->assertSame('11.000', $this->stock($context, 'Maracuyá'));
        $this->assertSame('12.000', $this->stock($context, 'Tumbo'));
        $this->assertSame('13.000', $this->stock($context, 'Copoazú'));

        $movementCount = InventoryMovement::query()->count();
        $second = app(ConfigureNaturalJuiceFlavorsAction::class)
            ->execute($context['company'], $context['branch'], $context['owner']);
        $this->assertSame(4, $second['stock_skipped']);
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
    }

    public function test_command_is_a_read_only_preview_without_apply(): void
    {
        $context = $this->context();

        $this->artisan('app:configure-natural-juice-flavors', [
            '--company' => $context['company']->ulid,
            '--branch' => $context['branch']->ulid,
        ])->expectsOutputToContain('Simulación finalizada')
            ->assertSuccessful();

        $this->assertTrue($context['historical']->refresh()->is_active);
        $this->assertSame(1, $context['product']->variants()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_prepared_flavors_keep_direct_stock_when_the_catalog_product_is_saved(): void
    {
        $context = $this->context();
        $this->configure($context);
        $variants = $context['product']->variants()->where('is_active', true)
            ->orderBy('sort_order')->get()->map(fn (ProductVariant $variant): array => [
                'ulid' => $variant->ulid,
                'name' => $variant->name,
                'sku' => null,
                'price' => $variant->price,
                'requires_preparation' => true,
                'track_stock' => true,
                'inventory_unit_id' => $context['unit']->id,
                'is_active' => true,
                'sort_order' => $variant->sort_order,
            ])->all();

        app(SaveProductAction::class)->execute($context['company'], [
            'category_id' => null,
            'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
            'type' => ProductType::Other->value,
            'is_active' => true,
        ], $variants, $context['product']);

        $this->assertSame(4, InventoryItem::query()
            ->whereIn('product_variant_id', collect($variants)->pluck('ulid')->map(
                fn (string $ulid): int => ProductVariant::query()->where('ulid', $ulid)->value('id'),
            ))->where('is_active', true)->count());
    }

    public function test_catalog_does_not_mix_an_active_recipe_with_direct_stock(): void
    {
        $context = $this->context();
        $payload = [[
            'ulid' => $context['historical']->ulid,
            'name' => 'Única',
            'sku' => null,
            'price' => '20.00',
            'requires_preparation' => true,
            'track_stock' => true,
            'inventory_unit_id' => $context['unit']->id,
            'is_active' => true,
            'sort_order' => 0,
        ]];

        $withoutDirectStock = $payload;
        $withoutDirectStock[0]['track_stock'] = false;
        app(SaveProductAction::class)->execute($context['company'], [
            'category_id' => null,
            'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
            'type' => ProductType::Other->value,
            'is_active' => true,
        ], $withoutDirectStock, $context['product']);
        $this->assertTrue($context['recipe']->refresh()->is_active);
        $this->assertNull($context['historical']->inventoryItem()->first());

        try {
            app(SaveProductAction::class)->execute($context['company'], [
                'category_id' => null,
                'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
                'type' => ProductType::Other->value,
                'is_active' => true,
            ], $payload, $context['product']);
            $this->fail('Una variante con receta no debe aceptar stock directo.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('receta activa', $exception->getMessage());
        }

        $this->assertNull($context['historical']->inventoryItem()->first());
        $this->assertTrue($context['recipe']->refresh()->is_active);
    }

    public function test_each_sale_consumes_only_its_flavor_once_without_recipe_or_pulp_consumption(): void
    {
        $context = $this->context();
        $this->configure($context);
        $order = $this->order($context['company'], $context['branch'], $context['owner']);
        $pineapple = $this->variant($context, 'Piña');
        $passionFruit = $this->variant($context, 'Maracuyá');

        $pineappleItem = app(AddOrderItemAction::class)
            ->execute($order, $pineapple, '1.000', $context['owner']);
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
        $this->assertSame('11.000', $this->stock($context, 'Maracuyá'));
        $this->assertSame('50.000', InventoryStock::query()
            ->where('inventory_item_id', $context['pulpaItem']->id)->value('quantity'));

        app(DispatchOrderToKitchenAction::class)->execute($order, $context['owner']);
        $this->assertSame('7.000', $this->stock($context, 'Piña'));
        $this->assertSame('11.000', $this->stock($context, 'Maracuyá'));
        $this->assertSame(1, InventoryMovement::query()
            ->where('reference_type', OrderItem::class)
            ->where('reference_id', $pineappleItem->id)
            ->where('inventory_item_id', $pineapple->inventoryItem->id)
            ->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $this->assertSame(0, InventoryMovement::query()
            ->where('reference_type', OrderItem::class)
            ->where('reference_id', $pineappleItem->id)
            ->where('inventory_item_id', $context['pulpaItem']->id)->count());

        app(ConsumeInventoryReservationAction::class)->execute($pineappleItem, $context['owner']);
        $this->assertSame('7.000', $this->stock($context, 'Piña'));

        $passionFruitItem = app(AddOrderItemAction::class)
            ->execute($order->refresh(), $passionFruit, '1.000', $context['owner']);
        app(DispatchOrderToKitchenAction::class)->execute($order->refresh(), $context['owner']);
        $this->assertSame('7.000', $this->stock($context, 'Piña'));
        $this->assertSame('10.000', $this->stock($context, 'Maracuyá'));
        $this->assertSame(InventoryReservationStatus::Consumed, $passionFruitItem->reservations()->sole()->status);
        $this->assertSame('50.000', InventoryStock::query()
            ->where('inventory_item_id', $context['pulpaItem']->id)->value('quantity'));
    }

    public function test_pos_lists_flavors_hides_unique_and_backend_blocks_exhausted_stock(): void
    {
        $context = $this->context();
        $this->configure($context);
        $order = $this->order($context['company'], $context['branch'], $context['owner']);
        $pineapple = $this->variant($context, 'Piña');

        app(AddOrderItemAction::class)->execute($order, $pineapple, '8.000', $context['owner']);

        $response = $this->actingInContext($context)
            ->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('Piña')
            ->assertSee('Maracuyá')
            ->assertSee('Tumbo')
            ->assertSee('Copoazú')
            ->assertSee('AGOTADO')
            ->assertDontSee('value='.chr(34).$context['historical']->ulid.chr(34), false);
        $this->assertStringContainsString('disabled', $response->getContent());

        $itemCount = $order->items()->count();
        $this->actingInContext($context)->post(route('orders.items.store', $order->ulid), [
            'variant' => $context['historical']->ulid,
            'quantity' => '1',
        ])->assertSessionHasErrors('item');
        $this->assertSame($itemCount, $order->items()->count());

        $this->expectException(InsufficientStockException::class);
        app(AddOrderItemAction::class)->execute($order, $pineapple, '1.000', $context['owner']);
    }

    public function test_draft_cancellation_releases_stock_and_sent_cancellation_reverses_consumption(): void
    {
        $context = $this->context();
        $this->configure($context);
        $pineapple = $this->variant($context, 'Piña');

        $draftOrder = $this->order($context['company'], $context['branch'], $context['owner']);
        $draft = app(AddOrderItemAction::class)
            ->execute($draftOrder, $pineapple, '1.000', $context['owner']);
        app(CancelOrderItemAction::class)->execute($draft, $context['owner']);
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
        $this->assertSame(
            InventoryReservationStatus::Released,
            InventoryReservation::query()->where('order_item_id', $draft->id)->sole()->status,
        );

        $sentOrder = $this->order($context['company'], $context['branch'], $context['owner']);
        $sent = app(AddOrderItemAction::class)
            ->execute($sentOrder, $pineapple, '1.000', $context['owner']);
        app(DispatchOrderToKitchenAction::class)->execute($sentOrder, $context['owner']);
        $this->assertSame('7.000', $this->stock($context, 'Piña'));

        app(CancelKitchenDispatchItemAction::class)
            ->execute($sent->refresh(), $context['owner'], 'Jugo devuelto');
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
        $this->assertSame(OrderItemStatus::Cancelled, $sent->refresh()->status);
        $this->assertSame(1, InventoryMovement::query()
            ->where('type', InventoryMovementType::Reversal->value)
            ->whereHas('reversalOf', fn ($query) => $query
                ->where('reference_type', OrderItem::class)
                ->where('reference_id', $sent->id))
            ->count());
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $company = Company::factory()->create(['table_charge_mode' => TableChargeMode::AtEnd]);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit,
        ]);
        $product = Product::factory()->for($company)->create([
            'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
            'type' => ProductType::Other,
            'is_active' => true,
        ]);
        $historical = ProductVariant::factory()->for($company)->for($product)->create([
            'name' => 'Única',
            'size_key' => 'unit',
            'price' => '20.00',
            'requires_preparation' => true,
            'is_active' => true,
        ]);
        $pulpa = Ingredient::factory()->for($company)->for($unit)->create(['name' => 'Pulpa de fruta']);
        $pulpaItem = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $pulpa->id,
            'name' => 'Pulpa de fruta',
            'is_active' => true,
        ]);
        app(ApplyInventoryMovementAction::class)->execute(
            $company, $branch, $pulpaItem, InventoryMovementType::AdjustmentIn,
            '50.000', '1.000000', $owner, reason: 'Stock de pulpa',
        );
        $recipe = Recipe::factory()->for($company)->for($historical, 'productVariant')->create([
            'name' => 'Receta histórica de jugo', 'is_active' => true,
        ]);
        RecipeItem::query()->create([
            'company_id' => $company->id,
            'recipe_id' => $recipe->id,
            'ingredient_id' => $pulpa->id,
            'component_type' => RecipeComponentType::Base,
            'quantity' => '1.000',
        ]);
        $historicalOrder = $this->order($company, $branch, $owner);
        $historicalItem = OrderItem::factory()->for($historicalOrder)->for($historical, 'productVariant')->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'created_by' => $owner->id,
            'unit_price' => '20.00',
            'line_total' => '20.00',
            'status' => OrderItemStatus::Ready,
        ]);

        return compact(
            'company', 'branch', 'owner', 'unit', 'product', 'historical',
            'historicalItem', 'pulpaItem', 'recipe',
        );
    }

    private function order(Company $company, Branch $branch, User $owner): Order
    {
        return Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'created_by' => $owner->id,
            'type' => OrderType::DineIn,
            'charge_mode' => TableChargeMode::AtEnd,
        ]);
    }

    private function configure(array $context): void
    {
        app(ConfigureNaturalJuiceFlavorsAction::class)
            ->execute($context['company'], $context['branch'], $context['owner']);
    }

    private function variant(array $context, string $name): ProductVariant
    {
        return ProductVariant::query()->where('product_id', $context['product']->id)
            ->where('name', $name)->firstOrFail();
    }

    private function stock(array $context, string $name): string
    {
        $variant = $this->variant($context, $name);

        return InventoryStock::query()->where('company_id', $context['company']->id)
            ->where('branch_id', $context['branch']->id)
            ->where('inventory_item_id', $variant->inventoryItem->id)
            ->value('quantity');
    }

    private function actingInContext(array $context): static
    {
        return $this->actingAs($context['owner'])->withSession([
            'active_company_id' => $context['company']->id,
            'active_branch_id' => $context['branch']->id,
        ]);
    }
}
