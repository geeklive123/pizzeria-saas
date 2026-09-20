<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelKitchenDispatchItemAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CancelSettledKitchenDispatchAction;
use App\Actions\ConfigureNaturalJuiceFlavorsAction;
use App\Actions\ConsumeInventoryReservationAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\SaveProductAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\RecipeComponentType;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
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
use App\Printing\Renderers\KitchenCommandRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_command_apply_requires_an_explicit_authorized_actor(): void
    {
        $context = $this->context();

        $this->artisan('app:configure-natural-juice-flavors', [
            '--company' => $context['company']->id,
            '--branch' => $context['branch']->id,
            '--apply' => true,
        ])->expectsOutputToContain('Indica --actor-email')
            ->assertFailed();

        $this->assertTrue($context['historical']->refresh()->is_active);
        $this->assertSame(1, $context['product']->variants()->count());
    }

    public function test_command_apply_with_an_authorized_actor_and_confirmation_is_transactional(): void
    {
        $context = $this->context();

        $this->artisan('app:configure-natural-juice-flavors', [
            '--company' => $context['company']->id,
            '--branch' => $context['branch']->id,
            '--actor-email' => $context['owner']->email,
            '--apply' => true,
        ])->expectsConfirmation(
            'Se desactivará Única y se cargará el stock inicial por sabor. ¿Continuar?',
            'yes',
        )->assertSuccessful();

        $this->assertFalse($context['historical']->refresh()->is_active);
        $this->assertSame(4, $context['product']->variants()->where('is_active', true)->count());
        $this->assertSame(5, InventoryMovement::query()->count());
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

    public function test_configured_prepared_flavors_can_be_saved_through_the_catalog_http_flow(): void
    {
        $context = $this->context();
        $this->configure($context);
        $variants = $context['product']->variants()->where('is_active', true)
            ->orderBy('sort_order')->get()->map(fn (ProductVariant $variant): array => [
                'ulid' => $variant->ulid,
                'name' => $variant->name,
                'sku' => null,
                'price' => $variant->price,
                'requires_preparation' => '1',
                'track_stock' => '1',
                'inventory_unit_id' => $context['unit']->id,
                'is_active' => '1',
                'sort_order' => (string) $variant->sort_order,
            ])->all();

        $this->actingInContext($context)
            ->put(route('products.update', $context['product']->ulid), [
                'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
                'type' => ProductType::Other->value,
                'is_active' => '1',
                'variants' => $variants,
            ])->assertSessionHasNoErrors();

        $this->assertSame(4, InventoryItem::query()
            ->whereIn('product_variant_id', $context['product']->variants()
                ->where('is_active', true)->pluck('id'))
            ->where('is_active', true)->count());
    }

    public function test_configuration_aborts_when_the_expected_product_and_historical_variant_ids_do_not_match(): void
    {
        $context = $this->context(false);

        $this->expectException(\DomainException::class);

        app(ConfigureNaturalJuiceFlavorsAction::class)
            ->execute($context['company'], $context['branch'], $context['owner']);
    }

    public function test_configuration_aborts_if_the_historical_price_changed(): void
    {
        $context = $this->context();
        $context['historical']->update(['price' => '21.00']);

        try {
            app(ConfigureNaturalJuiceFlavorsAction::class)
                ->execute($context['company'], $context['branch'], $context['owner']);
            $this->fail('La configuración no debe inferir un precio distinto al validado.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('precio esperado', $exception->getMessage());
        }

        $this->assertTrue($context['historical']->refresh()->is_active);
        $this->assertTrue($context['recipe']->refresh()->is_active);
        $this->assertSame(1, $context['product']->variants()->count());
    }

    public function test_existing_unmarked_movement_aborts_and_rolls_back_flavors_created_earlier(): void
    {
        $context = $this->context();
        $tumbo = ProductVariant::factory()->for($context['company'])->for($context['product'])->create([
            'name' => 'Tumbo',
            'price' => '20.00',
            'requires_preparation' => true,
        ]);
        $tumboItem = InventoryItem::query()->create([
            'company_id' => $context['company']->id,
            'unit_id' => $context['unit']->id,
            'product_variant_id' => $tumbo->id,
            'name' => 'Jugo Natural - Tumbo',
            'is_active' => true,
        ]);
        app(ApplyInventoryMovementAction::class)->execute(
            $context['company'],
            $context['branch'],
            $tumboItem,
            InventoryMovementType::AdjustmentIn,
            '1.000',
            '0.000000',
            $context['owner'],
            reason: 'Movimiento manual previo',
        );

        try {
            app(ConfigureNaturalJuiceFlavorsAction::class)
                ->execute($context['company'], $context['branch'], $context['owner']);
            $this->fail('Un movimiento previo ambiguo debe abortar toda la configuración.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('movimientos sin el marcador inicial', $exception->getMessage());
        }

        $this->assertTrue($context['historical']->refresh()->is_active);
        $this->assertTrue($context['recipe']->refresh()->is_active);
        $this->assertSame(['Única', 'Tumbo'], $context['product']->variants()
            ->orderBy('id')->pluck('name')->all());
        $this->assertSame(2, InventoryMovement::query()->count());
        $this->assertSame('1.000', InventoryStock::query()
            ->where('inventory_item_id', $tumboItem->id)->value('quantity'));
    }

    public function test_existing_stock_without_a_ledger_marker_aborts_instead_of_adding_initial_stock(): void
    {
        $context = $this->context();
        $pineapple = ProductVariant::factory()->for($context['company'])->for($context['product'])->create([
            'name' => 'Piña',
            'price' => '20.00',
            'requires_preparation' => true,
        ]);
        $pineappleItem = InventoryItem::query()->create([
            'company_id' => $context['company']->id,
            'unit_id' => $context['unit']->id,
            'product_variant_id' => $pineapple->id,
            'name' => 'Jugo Natural - Piña',
            'is_active' => true,
        ]);
        InventoryStock::query()->create([
            'company_id' => $context['company']->id,
            'branch_id' => $context['branch']->id,
            'inventory_item_id' => $pineappleItem->id,
            'quantity' => '2.000',
            'average_cost' => '0.000000',
        ]);

        $this->expectException(\DomainException::class);

        app(ConfigureNaturalJuiceFlavorsAction::class)
            ->execute($context['company'], $context['branch'], $context['owner']);
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

    public function test_catalog_does_not_mix_even_an_empty_active_recipe_with_direct_stock(): void
    {
        $context = $this->context();
        $context['recipe']->items()->delete();
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

        $this->expectException(\DomainException::class);

        app(SaveProductAction::class)->execute($context['company'], [
            'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
            'type' => ProductType::Other->value,
            'is_active' => true,
        ], $payload, $context['product']);
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

        $pineappleDispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $context['owner']);
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
        $command = app(KitchenCommandRenderer::class)->render($pineappleDispatch)->plainText;
        $this->assertStringContainsString('PIÑA', $command);
        $this->assertStringNotContainsString('ÚNICA', $command);

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

        $session = $this->cashSession($context);
        $movementCount = InventoryMovement::query()->count();
        app(RegisterPaymentAction::class)->execute(
            $order->refresh(),
            $session,
            PaymentMethod::Qr,
            '40.00',
            $context['owner'],
            'juice-table-at-end',
            reference: 'QR-JUGOS',
        );
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame('7.000', $this->stock($context, 'Piña'));
        $this->assertSame('10.000', $this->stock($context, 'Maracuyá'));
    }

    public function test_catalog_keeps_pizza_restriction_and_does_not_create_unrequested_stock(): void
    {
        $context = $this->context();
        $pizza = Product::factory()->for($context['company'])->create([
            'name' => 'Pizza de prueba',
            'type' => ProductType::Pizza,
        ]);

        try {
            app(SaveProductAction::class)->execute($context['company'], [
                'name' => 'Pizza de prueba',
                'type' => ProductType::Pizza->value,
                'is_active' => true,
            ], [[
                'name' => 'Grande',
                'price' => '50.00',
                'requires_preparation' => true,
                'track_stock' => true,
                'inventory_unit_id' => $context['unit']->id,
                'is_active' => true,
                'sort_order' => 0,
            ]], $pizza);
            $this->fail('Una pizza preparada no debe aceptar stock directo.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('pizzas controlan inventario', $exception->getMessage());
        }
        $this->assertSame(0, $pizza->variants()->count());

        $prepared = Product::factory()->for($context['company'])->create([
            'name' => 'Preparado sin stock',
            'type' => ProductType::Other,
        ]);
        app(SaveProductAction::class)->execute($context['company'], [
            'name' => 'Preparado sin stock',
            'type' => ProductType::Other->value,
            'is_active' => true,
        ], [[
            'name' => 'Única',
            'price' => '10.00',
            'requires_preparation' => true,
            'track_stock' => false,
            'inventory_unit_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]], $prepared);
        $this->assertNull($prepared->variants()->sole()->inventoryItem);
    }

    public function test_recipe_editor_cannot_activate_a_recipe_on_a_direct_stock_flavor(): void
    {
        $context = $this->context();
        $this->configure($context);
        $pineapple = $this->variant($context, 'Piña');

        $this->expectException(ValidationException::class);

        app(UpdateRecipeAction::class)->execute(
            $context['company'],
            $pineapple,
            [[
                'ingredient_id' => $context['pulpaItem']->ingredient_id,
                'component_type' => RecipeComponentType::Base->value,
                'quantity' => '1.000',
            ]],
            'Receta accidental',
            true,
        );
    }

    public function test_takeaway_per_batch_payment_consumes_once_and_paid_reversal_restores_once(): void
    {
        $context = $this->context();
        $context['company']->update(['table_charge_mode' => TableChargeMode::PerBatch]);
        $this->configure($context);
        $pineapple = $this->variant($context, 'Piña');
        $order = Order::factory()->for($context['branch'])->create([
            'company_id' => $context['company']->id,
            'created_by' => $context['owner']->id,
            'type' => OrderType::Takeaway,
            'charge_mode' => TableChargeMode::PerBatch,
        ]);
        $item = app(AddOrderItemAction::class)
            ->execute($order, $pineapple, '1.000', $context['owner']);
        $dispatch = app(DispatchOrderToKitchenAction::class)
            ->execute($order, $context['owner']);

        $this->assertSame(KitchenDispatchStatus::AwaitingPayment, $dispatch->status);
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
        $this->assertSame(InventoryReservationStatus::Reserved, $item->reservations()->sole()->status);

        $session = $this->cashSession($context);
        app(RegisterPaymentAction::class)->execute(
            $order->refresh(),
            $session,
            PaymentMethod::Qr,
            '20.00',
            $context['owner'],
            'juice-takeaway-batch',
            reference: 'QR-JUGO',
            dispatch: $dispatch,
        );

        $this->assertSame(KitchenDispatchStatus::Settled, $dispatch->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame('7.000', $this->stock($context, 'Piña'));
        $this->assertSame(1, InventoryMovement::query()
            ->where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)
            ->where('type', InventoryMovementType::OrderConsumption->value)->count());

        app(RegisterPaymentAction::class)->execute(
            $order,
            $session,
            PaymentMethod::Qr,
            '20.00',
            $context['owner'],
            'juice-takeaway-batch',
            reference: 'QR-JUGO',
            dispatch: $dispatch,
        );
        $this->assertSame('7.000', $this->stock($context, 'Piña'));

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($dispatch->refresh(), $context['owner'], 'Devolución de jugo');
        $this->assertSame('8.000', $this->stock($context, 'Piña'));
        $this->assertSame(1, InventoryMovement::query()
            ->where('type', InventoryMovementType::Reversal->value)
            ->whereHas('reversalOf', fn ($query) => $query
                ->where('reference_type', OrderItem::class)
                ->where('reference_id', $item->id))
            ->count());

        try {
            app(CancelSettledKitchenDispatchAction::class)
                ->execute($dispatch->refresh(), $context['owner'], 'Segundo intento');
            $this->fail('La reversión pagada no debe poder duplicarse.');
        } catch (\DomainException) {
            $this->assertSame('8.000', $this->stock($context, 'Piña'));
        }
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
    private function context(bool $useExpectedIds = true): array
    {
        $company = Company::factory()->create(['table_charge_mode' => TableChargeMode::AtEnd]);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit,
        ]);
        $productAttributes = [
            'name' => ConfigureNaturalJuiceFlavorsAction::PRODUCT_NAME,
            'type' => ProductType::Other,
            'is_active' => true,
        ];
        if ($useExpectedIds) {
            $productAttributes['id'] = ConfigureNaturalJuiceFlavorsAction::PRODUCT_ID;
        }
        $product = Product::factory()->for($company)->create($productAttributes);
        $historicalAttributes = [
            'name' => 'Única',
            'size_key' => 'unit',
            'price' => '20.00',
            'requires_preparation' => true,
            'is_active' => true,
        ];
        if ($useExpectedIds) {
            $historicalAttributes['id'] = ConfigureNaturalJuiceFlavorsAction::HISTORICAL_VARIANT_ID;
        }
        $historical = ProductVariant::factory()->for($company)->for($product)->create($historicalAttributes);
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

    private function cashSession(array $context): CashSession
    {
        $register = CashRegister::query()->firstOrCreate([
            'company_id' => $context['company']->id,
            'branch_id' => $context['branch']->id,
            'name' => 'Caja de prueba',
        ], ['is_active' => true]);

        return app(OpenCashSessionAction::class)
            ->execute($register, '0.00', $context['owner']);
    }
}
