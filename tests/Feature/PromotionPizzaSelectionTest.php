<?php

namespace Tests\Feature;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\AddPromotionToOrderAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelKitchenDispatchItemAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CancelSettledKitchenDispatchAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\SavePromotionAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryMovementType;
use App\Enums\MembershipRole;
use App\Enums\ModifierOptionType;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\ProductModifierPurpose;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Membership;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductModifier;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Printing\Renderers\CustomerTicketRenderer;
use App\Printing\Renderers\KitchenCommandRenderer;
use App\Services\OrderFinancialService;
use App\Services\PromotionAvailabilityService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionPizzaSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_normalized_group_and_familiar_is_the_internal_key(): void
    {
        $f = $this->fixture(groupQuantity: 1);

        $this->assertDatabaseHas('promotion_selection_groups', [
            'promotion_id' => $f['promotion']->id,
            'category_id' => $f['category']->id,
            'size_key' => 'familiar',
            'quantity' => 1,
        ]);
        $this->assertSame('familiar', $f['promotion']->selectionGroups->sole()->size_key);
    }

    public function test_two_selected_pizzas_charge_only_promotion_base_and_reserve_recipes_and_drink_once(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [$this->pizza($f, $f['a']), $this->pizza($f, $f['b'])]);

        $this->assertSame('129.00', $item->unit_price);
        $this->assertSame('129.00', $item->line_total);
        $this->assertTrue($item->requires_preparation);
        $this->assertCount(2, $item->configuration_snapshot['pizzas']);
        $this->assertReservation($item, $f['drink_item'], '1.000');
        $this->assertReservation($item, $f['dough_item'], '200.000');
        $this->assertReservation($item, $f['a_item'], '50.000');
        $this->assertReservation($item, $f['b_item'], '50.000');
    }

    public function test_repeated_flavor_complete_and_fused_pizzas_reuse_normal_composition_rules(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [
            $this->pizza($f, $f['a']),
            $this->pizza($f, $f['a'], [$f['b']]),
        ]);

        $this->assertSame('1/2', $item->configuration_snapshot['pizzas'][1]['sections'][0]['fraction']);
        $this->assertSame('1/2', $item->configuration_snapshot['pizzas'][1]['sections'][1]['fraction']);
        $this->assertReservation($item, $f['dough_item'], '200.000');
        $this->assertReservation($item, $f['a_item'], '75.000');
        $this->assertReservation($item, $f['b_item'], '25.000');
    }

    public function test_allowed_extra_increases_total_and_inventory_but_not_discountable_pizza_base(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $item = $this->add($f, [$this->pizza($f, $f['a'], modifiers: [['option' => $f['extra']->ulid]])]);
        $financial = app(OrderFinancialService::class)->preview([$item]);

        $this->assertSame('134.00', $item->line_total);
        $this->assertSame('5.00', $item->configuration_snapshot['promotion']['extras_price']);
        $this->assertReservation($item, $f['cheese_item'], '20.000');
        $this->assertSame('0.00', $financial['pizza_base']);
        $this->assertSame('5.00', $financial['extras']);
        $this->assertSame('129.00', $financial['other']);
    }

    public function test_manual_discount_applies_only_to_normal_pizza_and_never_to_promotion_base_or_extras(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $promotionItem = $this->add($f, [$this->pizza($f, $f['a'], modifiers: [['option' => $f['extra']->ulid]])]);
        $normalPizza = app(AddConfiguredPizzaAction::class)->execute(
            $f['order'],
            [['variant' => $f['a']->ulid]],
            '2.000',
            $f['owner'],
            OrderType::DineIn,
        );
        $financial = app(OrderFinancialService::class)->preview([$promotionItem, $normalPizza], '10');

        $this->assertSame('140.00', $financial['pizza_base']);
        $this->assertSame('14.00', $financial['discount']);
        $this->assertSame('0.00', $financial['lines'][$promotionItem->id]['discount']);
        $this->assertSame('134.00', $financial['lines'][$promotionItem->id]['net']);
    }

    public function test_removal_changes_requirements_and_snapshot_survives_catalog_edits(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $item = $this->add($f, [$this->pizza($f, $f['a'], modifiers: [['option' => $f['remove']->ulid]])]);
        $f['promotion']->productVariant->product->update(['name' => 'PROMO CAMBIADA']);
        app(UpdateRecipeAction::class)->execute($f['company'], $f['a'], [
            ['ingredient_id' => $f['dough']->id, 'component_type' => 'base', 'quantity' => '999.000'],
        ], 'Receta cambiada');

        $snapshot = $item->refresh()->configuration_snapshot;
        $this->assertDatabaseMissing('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $f['a_item']->id]);
        $this->assertSame('PROMO PIZZAS', $snapshot['promotion']['name']);
        $this->assertSame('QUESUDA', $snapshot['pizzas'][0]['sections'][0]['product']);
        $this->assertSame('100.000', collect($snapshot['pizzas'][0]['requirements'])->firstWhere('name', 'Masa')['quantity']);
    }

    public function test_backend_rejects_wrong_category_size_count_and_disallowed_extras(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $otherCategory = Category::factory()->for($f['company'])->create(['name' => 'Otra']);
        $f['b']->product->update(['category_id' => $otherCategory->id]);
        $this->assertDomainFailure(fn () => $this->add($f, [$this->pizza($f, $f['b'])]), 'categoría');

        $f['b']->product->update(['category_id' => $f['category']->id]);
        $f['b']->update(['size_key' => 'mediana', 'name' => 'Mediana']);
        $this->assertDomainFailure(fn () => $this->add($f, [$this->pizza($f, $f['b']->refresh())]), 'tamaño');
        $this->assertDomainFailure(fn () => $this->add($f, []), 'exactamente');

        $f['promotion']->selectionGroups()->update(['allow_extras' => false]);
        $this->assertDomainFailure(fn () => $this->add($f, [$this->pizza($f, $f['a'], modifiers: [['option' => $f['extra']->ulid]])]), 'no permite extras');
    }

    public function test_insufficient_stock_rolls_back_whole_promotion_without_partial_reservations(): void
    {
        $f = $this->fixture(stock: '150.000');

        try {
            $this->add($f, [$this->pizza($f, $f['a']), $this->pizza($f, $f['b'])]);
            $this->fail('La promoción debía fallar por falta de masa.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
        }
    }

    public function test_availability_requires_at_least_one_real_stock_feasible_selection(): void
    {
        $available = $this->fixture(stock: '1000.000');
        $unavailable = $this->fixture(stock: '150.000');

        $this->assertSame('1.000', app(PromotionAvailabilityService::class)->calculate($available['promotion'], $available['branch'])->availableQuantity);
        $this->assertSame('0.000', app(PromotionAvailabilityService::class)->calculate($unavailable['promotion'], $unavailable['branch'])->availableQuantity);
    }

    public function test_reservation_consumption_retry_and_cancellation_flows_cover_the_whole_unit(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [$this->pizza($f, $f['a']), $this->pizza($f, $f['b'])]);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        app(DispatchOrderToKitchenAction::class)->execute($f['order']->refresh(), $f['owner']);

        $this->assertSame(4, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption)->count());
        $this->assertSame(0, InventoryReservation::query()->where('status', 'reserved')->count());
        app(CancelKitchenDispatchItemAction::class)->execute($item->refresh(), $f['owner'], 'Error de cocina');
        $this->assertSame(4, InventoryMovement::query()->where('type', InventoryMovementType::Reversal)->count());
        $this->assertSame('cancelled', $dispatch->refresh()->status->value);

        $draft = $this->fixture(groupQuantity: 1);
        $draftItem = $this->add($draft, [$this->pizza($draft, $draft['a'])]);
        app(CancelOrderItemAction::class)->execute($draftItem, $draft['owner']);
        $this->assertSame(0, $draftItem->reservations()->where('status', 'reserved')->count());
    }

    public function test_pizza_promotion_is_visible_in_kds_and_kitchen_print_with_fixed_component(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $item = $this->add($f, [$this->pizza($f, $f['a'])]);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $text = app(KitchenCommandRenderer::class)->render($dispatch)->plainText;

        $this->assertStringContainsString('PROMO PIZZAS', $text);
        $this->assertStringContainsString('PIZZA 1 · GRANDE', $text);
        $this->assertStringContainsString('QUESUDA', $text);
        $this->assertStringContainsString('COCA-COLA 2 L', $text);
        $this->actingAs($f['owner'])->withSession(['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id])
            ->get(route('kitchen.index'))->assertOk()->assertSee('PROMO PIZZAS')->assertSee('QUESUDA')->assertSee('Coca-Cola 2 L');
        $this->assertTrue($item->requires_preparation);
    }

    public function test_customer_ticket_prints_base_pizzas_fixed_component_and_extras_separately(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $this->add($f, [$this->pizza($f, $f['a'], modifiers: [['option' => $f['extra']->ulid]])]);
        $text = app(CustomerTicketRenderer::class)->renderProvisional($f['order']->refresh(), $f['owner'])->plainText;

        $this->assertStringContainsString('PROMO PIZZAS', $text);
        $this->assertStringContainsString('Bs 129,00', $text);
        $this->assertStringContainsString('Pizza 1: QUESUDA Grande', $text);
        $this->assertStringContainsString('Coca-Cola 2 L', $text);
        $this->assertStringContainsString('EXTRAS', $text);
        $this->assertStringContainsString('Bs 5,00', $text);
        $this->assertStringNotContainsString('Bs 70,00', $text);
    }

    public function test_promotion_accepts_current_cash_and_qr_payment_flows_without_reconsuming_inventory(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $this->add($f, [$this->pizza($f, $f['a'], modifiers: [['option' => $f['extra']->ulid]])]);
        app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $session = $this->cashSession($f);

        app(RegisterPaymentAction::class)->execute($f['order']->refresh(), $session, PaymentMethod::Cash, '100.00', $f['owner'], 'promo-cash', '100.00');
        app(RegisterPaymentAction::class)->execute($f['order']->refresh(), $session, PaymentMethod::Qr, '34.00', $f['owner'], 'promo-qr', reference: 'QR-TEST');

        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertSame(4, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption)->count());
        $this->assertDatabaseHas('payments', ['method' => 'cash', 'amount' => 100]);
        $this->assertDatabaseHas('payments', ['method' => 'qr', 'amount' => 34]);
    }

    public function test_per_batch_payment_settles_and_consumes_promotion_once(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $f['order']->update(['charge_mode' => TableChargeMode::PerBatch]);
        $this->add($f, [$this->pizza($f, $f['a'])]);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order']->refresh(), $f['owner']);
        $session = $this->cashSession($f);

        app(RegisterPaymentAction::class)->execute($f['order']->refresh(), $session, PaymentMethod::Qr, '129.00', $f['owner'], 'promo-batch', reference: 'QR-BATCH', dispatch: $dispatch);

        $this->assertSame('settled', $dispatch->refresh()->status->value);
        $this->assertSame(3, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption)->count());
        $this->assertSame(0, InventoryReservation::query()->where('status', 'reserved')->count());
    }

    public function test_paid_takeaway_batch_reversal_reverses_payment_and_all_promotion_inventory(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $f['order'] = app(CreateTakeawayOrderAction::class)->execute($f['company'], $f['branch'], $f['owner']);
        $item = $this->add($f, [$this->pizza($f, $f['a'])]);
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['owner']);
        $session = $this->cashSession($f);
        app(RegisterPaymentAction::class)->execute($f['order']->refresh(), $session, PaymentMethod::Qr, '129.00', $f['owner'], 'promo-paid-reversal', reference: 'QR-REV', dispatch: $dispatch);

        app(CancelSettledKitchenDispatchAction::class)->execute($dispatch->refresh(), $f['owner'], 'Reversión de prueba');

        $this->assertSame('cancelled', $item->refresh()->status->value);
        $this->assertSame(3, InventoryMovement::query()->where('type', InventoryMovementType::Reversal)->count());
        $this->assertDatabaseHas('payments', ['idempotency_key' => 'promo-paid-reversal', 'status' => 'reversed']);
    }

    public function test_repeated_flavor_is_rejected_when_group_disables_it(): void
    {
        $f = $this->fixture();
        $f['promotion']->selectionGroups()->update(['allow_repeated_flavors' => false]);

        $this->assertDomainFailure(fn () => $this->add($f, [$this->pizza($f, $f['a']), $this->pizza($f, $f['a'])]), 'repetir sabores');
    }

    public function test_other_company_variant_is_rejected(): void
    {
        $f = $this->fixture(groupQuantity: 1);
        $other = $this->fixture(groupQuantity: 1);

        $this->assertDomainFailure(fn () => $this->add($f, [$this->pizza($f, $other['a'])]), 'pizza activa');
    }

    private function fixture(int $groupQuantity = 2, string $stock = '1000.000'): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);
        $grams = Unit::factory()->for($company)->create(['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight]);
        $units = Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);
        $category = Category::factory()->for($company)->create(['name' => 'Las de siempre - con maña']);
        [$dough, $doughItem] = $this->ingredient($company, $grams, 'Masa');
        [$cheese, $cheeseItem] = $this->ingredient($company, $grams, 'Mozzarella');
        [$aIngredient, $aItem] = $this->ingredient($company, $grams, 'Queso sabor');
        [$bIngredient, $bItem] = $this->ingredient($company, $grams, 'Tradición sabor');
        [, $drinkItem] = $this->ingredient($company, $units, 'Coca-Cola 2 L');
        foreach ([$doughItem, $cheeseItem, $aItem, $bItem, $drinkItem] as $inventoryItem) {
            app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventoryItem, InventoryMovementType::AdjustmentIn, $stock, '1.000000', $owner, reason: 'Stock test promoción pizza');
        }
        $a = $this->flavor($company, $category, 'QUESUDA', $dough, $aIngredient, '70.00');
        $b = $this->flavor($company, $category, 'TRADICIÓN', $dough, $bIngredient, '75.00');
        $modifier = ProductModifier::query()->create(['company_id' => $company->id, 'purpose' => ProductModifierPurpose::OrderModifier, 'name' => 'Personalización', 'is_active' => true]);
        $extra = ModifierOption::query()->create(['company_id' => $company->id, 'product_modifier_id' => $modifier->id, 'name' => 'Extra queso', 'type' => ModifierOptionType::Add, 'price_delta' => '5.00', 'inventory_item_id' => $cheeseItem->id, 'ingredient_id' => $cheese->id, 'quantity' => '20.000', 'unit_id' => $grams->id, 'is_active' => true]);
        $remove = ModifierOption::query()->create(['company_id' => $company->id, 'product_modifier_id' => $modifier->id, 'name' => 'Sin queso sabor', 'type' => ModifierOptionType::Remove, 'price_delta' => '0.00', 'inventory_item_id' => $aItem->id, 'ingredient_id' => $aIngredient->id, 'unit_id' => $grams->id, 'is_active' => true]);
        $promotion = app(SavePromotionAction::class)->execute($company, [
            'name' => 'PROMO PIZZAS', 'description' => null, 'price' => '129.00', 'is_active' => true,
            'components' => [['inventory_item_ulid' => $drinkItem->ulid, 'quantity' => '1.000']],
            'selection_groups' => [['category_ulid' => $category->ulid, 'size_key' => 'familiar', 'quantity' => $groupQuantity, 'allow_repeated_flavors' => true, 'allow_fusions' => true, 'allow_extras' => true, 'allow_removals' => true]],
        ], $owner);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner, null, TableChargeMode::AtEnd);

        return compact('company', 'branch', 'owner', 'category', 'dough', 'cheese', 'a', 'b', 'extra', 'remove', 'promotion', 'order') + [
            'dough_item' => $doughItem,
            'cheese_item' => $cheeseItem,
            'a_item' => $aItem,
            'b_item' => $bItem,
            'drink_item' => $drinkItem,
        ];
    }

    private function flavor(Company $company, Category $category, string $name, Ingredient $dough, Ingredient $topping, string $price): ProductVariant
    {
        $product = Product::factory()->for($company)->for($category)->create(['name' => $name, 'type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['name' => 'Familiar', 'size_key' => 'familiar', 'price' => $price, 'requires_preparation' => true]);
        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $dough->id, 'component_type' => 'base', 'quantity' => '100.000'],
            ['ingredient_id' => $topping->id, 'component_type' => 'topping', 'quantity' => '50.000'],
        ], 'Receta '.$name);

        return $variant;
    }

    private function ingredient(Company $company, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'ingredient_id' => $ingredient->id, 'name' => $name, 'is_active' => true]);

        return [$ingredient, $item];
    }

    private function pizza(array $f, ProductVariant $first, array $additional = [], array $modifiers = []): array
    {
        return [
            'group' => $f['promotion']->selectionGroups->sole()->ulid,
            'sections' => collect([$first, ...$additional])->map(fn ($variant): array => ['variant' => $variant->ulid])->all(),
            'modifiers' => $modifiers,
        ];
    }

    private function add(array $f, array $pizzas)
    {
        return app(AddPromotionToOrderAction::class)->execute($f['order'], $f['promotion'], '1.000', $f['owner'], OrderType::DineIn, pizzas: $pizzas);
    }

    private function assertReservation($item, InventoryItem $inventoryItem, string $quantity): void
    {
        $this->assertSame($quantity, InventoryReservation::query()->where('order_item_id', $item->id)->where('inventory_item_id', $inventoryItem->id)->value('quantity'));
    }

    private function assertDomainFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una excepción de dominio.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function cashSession(array $f)
    {
        $register = CashRegister::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'name' => 'Caja test', 'is_active' => true]);

        return app(OpenCashSessionAction::class)->execute($register, '0.00', $f['owner']);
    }
}
