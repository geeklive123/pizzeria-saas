<?php

namespace Tests\Feature;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\UpdateConfiguredPizzaAction;
use App\Actions\UpdateOrderItemQuantityAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryMovementType;
use App\Enums\MembershipRole;
use App\Enums\ModifierOptionType;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
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
use App\Models\PackagingRule;
use App\Models\Product;
use App\Models\ProductModifier;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PizzaCompositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_flavor_is_stored_as_one_whole_section(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);

        $this->assertCount(1, $item->sections);
        $this->assertSame('1/1', $item->sections->first()->fractionLabel());
    }

    public function test_half_and_half_accepts_two_exact_halves(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);

        $this->assertSame(['1/2', '1/2'], $item->sections->map->fractionLabel()->all());
    }

    public function test_three_flavors_accept_three_exact_thirds(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 3], [$f['b'], 1, 3], [$f['c'], 1, 3]], OrderType::DineIn);

        $this->assertSame(['1/3', '1/3', '1/3'], $item->sections->map->fractionLabel()->all());
    }

    public function test_four_flavors_accept_four_exact_quarters(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 4], [$f['b'], 1, 4], [$f['c'], 1, 4], [$f['d'], 1, 4]], OrderType::DineIn);

        $this->assertSame(['1/4', '1/4', '1/4', '1/4'], $item->sections->map->fractionLabel()->all());
    }

    public function test_backend_ignores_manipulated_client_fractions_and_generates_equal_sections(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 999, 1000], [$f['b'], 1, 1000]], OrderType::DineIn);

        $this->assertSame(['1/2', '1/2'], $item->sections->map->fractionLabel()->all());
    }

    public function test_duplicate_flavors_are_rejected_by_the_domain_with_a_friendly_message(): void
    {
        $f = $this->fixture();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción "1 sabor".');
        $this->add($f, [[$f['a'], 1, 2], [$f['a'], 1, 2]], OrderType::DineIn);
    }

    public function test_pos_hides_fraction_fields_and_http_duplicate_message_is_in_spanish(): void
    {
        $f = $this->fixture();
        $context = $this->actingAs($f['owner'])->withSession([
            'active_company_id' => $f['company']->id,
            'active_branch_id' => $f['branch']->id,
        ]);

        $context->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('¿Cuántos sabores?')
            ->assertDontSee('Numerador')
            ->assertDontSee('Denominador');

        $context->post(route('orders.items.store', $f['order']->ulid), [
            'sections' => [
                ['variant' => $f['a']->ulid],
                ['variant' => $f['a']->ulid],
            ],
            'quantity' => '1.000',
            'fulfillment_type' => OrderType::DineIn->value,
        ])->assertSessionHasErrors([
            'sections.0.variant' => 'Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción "1 sabor".',
        ]);

        $context->post(route('orders.items.store', $f['order']->ulid), [
            'sections' => [
                ['variant' => $f['a']->ulid],
                ['variant' => $f['b']->ulid],
            ],
            'quantity' => '1.000',
            'fulfillment_type' => OrderType::DineIn->value,
        ])->assertSessionHasNoErrors();

        $item = $f['order']->items()->firstOrFail()->load('sections');
        $this->assertSame(['1/2', '1/2'], $item->sections->map->fractionLabel()->all());
        $this->assertSame('80.00', $item->unit_price);
    }

    public function test_more_than_four_sections_is_rejected(): void
    {
        $f = $this->fixture();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('entre 1 y 4');
        $this->add($f, [[$f['a'], 1, 5], [$f['b'], 1, 5], [$f['c'], 1, 5], [$f['d'], 1, 5], [$f['a'], 1, 5]], OrderType::DineIn);
    }

    public function test_incompatible_structured_sizes_are_rejected(): void
    {
        $f = $this->fixture();
        $f['b']->update(['name' => 'Mediana', 'size_key' => 'mediana']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mismo tamaño compatible');
        $this->add($f, [[$f['a'], 1, 2], [$f['b']->refresh(), 1, 2]], OrderType::DineIn);
    }

    public function test_legacy_internal_keys_do_not_hide_flavors_with_the_same_visible_size(): void
    {
        $f = $this->fixture();
        $f['b']->update(['size_key' => 'clave-antigua']);

        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b']->refresh(), 1, 2]], OrderType::DineIn);

        $this->assertCount(2, $item->sections);
        $this->assertSame('familiar', $item->configuration_snapshot['size_key']);
        $this->assertSame('Pizza Grande', $item->displayName());
        $this->assertSame('familiar', $item->sections->first()->productVariant->size_key);
    }

    public function test_price_is_the_highest_flavor_price(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['d'], 1, 2]], OrderType::DineIn);

        $this->assertSame('85.00', $item->unit_price);
    }

    public function test_final_price_and_section_prices_are_snapshotted(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);
        $f['b']->update(['price' => '99.00']);

        $this->assertSame('80.00', $item->refresh()->unit_price);
        $this->assertSame('80.00', $item->sections->last()->unit_price_snapshot);
    }

    public function test_base_is_reserved_once_for_a_fused_pizza(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);

        $this->assertReservation($item, $f['base_item'], '450.000');
    }

    public function test_toppings_are_fractioned_by_section(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);

        $this->assertReservation($item, $f['a_item'], '60.000');
        $this->assertReservation($item, $f['b_item'], '60.000');
    }

    public function test_thirds_are_aggregated_rationally_before_rounding(): void
    {
        $f = $this->fixture(sharedTopping: true);
        $item = $this->add($f, [[$f['a'], 1, 3], [$f['b'], 1, 3], [$f['c'], 1, 3]], OrderType::DineIn);

        $this->assertReservation($item, $f['a_item'], '100.000');
        $this->assertSame('aggregate_rational_then_half_up_3_decimals', $item->configuration_snapshot['rounding']);
    }

    public function test_extra_increases_price(): void
    {
        $f = $this->fixture();
        $extra = $this->extra($f, '5.00', '80.000');
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn, [[$extra, null]]);

        $this->assertSame('80.00', $item->unit_price);
    }

    public function test_extra_increases_inventory_reservation(): void
    {
        $f = $this->fixture();
        $extra = $this->extra($f, '5.00', '80.000');
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn, [[$extra, null]]);

        $this->assertReservation($item, $f['cheese_item'], '380.000');
    }

    public function test_remove_reduces_inventory_reservation(): void
    {
        $f = $this->fixture();
        $remove = $this->remove($f, $f['a_item'], 'Sin A');
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn, [[$remove, null]]);

        $this->assertDatabaseMissing('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $f['a_item']->id]);
    }

    public function test_remove_does_not_reduce_price(): void
    {
        $f = $this->fixture();
        $remove = $this->remove($f, $f['a_item'], 'Sin A');
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn, [[$remove, null]]);

        $this->assertSame('75.00', $item->unit_price);
    }

    public function test_section_modifier_only_removes_that_sections_contribution(): void
    {
        $f = $this->fixture(sharedTopping: true);
        $remove = $this->remove($f, $f['a_item'], 'Sin topping compartido');
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn, [[$remove, 1]]);

        $this->assertReservation($item, $f['a_item'], '50.000');
        $this->assertSame(1, $item->modifiers->first()->section->position);
    }

    public function test_out_of_stock_composition_is_rejected_with_friendly_message(): void
    {
        $f = $this->fixture(stock: '50.000');
        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('No disponible: falta');
        $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);
    }

    public function test_failed_composition_rolls_back_item_and_partial_reservations(): void
    {
        $f = $this->fixture(stock: '100.000');
        try {
            $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);
            $this->fail('The pizza should not have enough base inventory.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
        }
    }

    public function test_editing_draft_recalculates_price_sections_and_reservations(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);
        $updated = app(UpdateConfiguredPizzaAction::class)->execute($item, $this->sections([[$f['b'], 1, 1]]), '1.000', $f['owner'], OrderType::DineIn);

        $this->assertSame('80.00', $updated->unit_price);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertReservation($updated, $f['b_item'], '120.000');
        $this->assertDatabaseMissing('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $f['a_item']->id, 'status' => 'reserved']);
    }

    public function test_sent_line_cannot_be_edited_silently(): void
    {
        $f = $this->fixture();
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);
        app(DispatchOrderToKitchenAction::class)->execute($item->order, $f['owner']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('debe cancelarse');
        app(UpdateConfiguredPizzaAction::class)->execute($item->refresh(), $this->sections([[$f['b'], 1, 1]]), '1.000', $f['owner'], OrderType::DineIn);
    }

    public function test_kds_shows_sections_modifiers_and_no_price(): void
    {
        $f = $this->fixture(sharedTopping: true);
        $remove = $this->remove($f, $f['a_item'], 'Sin topping');
        // Este test valida la representación de una pizza compuesta en KDS.
        // Usamos consumo en mesa para que el dispatch la libere a cocina sin
        // depender del flujo de cobro previo propio de Takeaway.
        $table = RestaurantTable::factory()->for($f['branch'])->create(['company_id' => $f['company']->id]);
        $f['order'] = app(OpenTableOrderAction::class)->execute($f['company'], $f['branch'], $table, $f['owner'], null, TableChargeMode::AtEnd);
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn, [[$remove, 1]]);
        app(DispatchOrderToKitchenAction::class)->execute($item->order, $f['owner']);

        $this->actingAs($f['owner'])->withSession(['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id])
            ->get(route('kitchen.index'))->assertOk()->assertSee('1/2')->assertSee('Sabor A')->assertSee('SIN TOPPING')->assertDontSee('Bs 80');
    }

    public function test_takeaway_reserves_packaging_rule(): void
    {
        $f = $this->fixture();
        $this->packaging($f);
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::Takeaway);

        $this->assertReservation($item, $f['box_item'], '1.000');
    }

    public function test_dine_in_does_not_reserve_packaging(): void
    {
        $f = $this->fixture();
        $this->packaging($f);
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);

        $this->assertDatabaseMissing('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $f['box_item']->id]);
    }

    public function test_fulfillment_change_updates_packaging_atomically(): void
    {
        $f = $this->fixture();
        $this->packaging($f);
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);
        $updated = app(UpdateOrderItemQuantityAction::class)->execute($item, '1.000', $f['owner'], OrderType::Takeaway);
        $this->assertReservation($updated, $f['box_item'], '1.000');

        $updated = app(UpdateOrderItemQuantityAction::class)->execute($updated, '1.000', $f['owner'], OrderType::DineIn);
        $this->assertDatabaseMissing('inventory_reservations', ['order_item_id' => $item->id, 'inventory_item_id' => $f['box_item']->id, 'status' => 'reserved']);
    }

    public function test_composition_rejects_variants_from_another_company(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();

        $this->expectException(DomainException::class);
        $this->add($f, [[$f['a'], 1, 2], [$other['a'], 1, 2]], OrderType::DineIn);
    }

    public function test_dulce_fuego_and_la_chura_allow_different_sauces_and_consolidate_shared_ingredients(): void
    {
        $f = $this->differentBaseFixture(doughQuantities: ['a' => '320.000', 'b' => '305.000']);
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);

        $this->assertSame(['DULCE FUEGO', 'LA CHURA'], $item->sections->pluck('product_name_snapshot')->all());
        $this->assertSame(['1/2', '1/2'], $item->sections->map->fractionLabel()->all());
        $this->assertReservation($item, $f['base_item'], '320.000');
        $this->assertSame(1, $item->reservations()->where('inventory_item_id', $f['base_item']->id)->count());
        $this->assertReservation($item, $f['cheese_item'], '180.000');
        $this->assertReservation($item, $f['tomato_item'], '50.000');
        $this->assertReservation($item, $f['white_sauce_item'], '50.000');
        $this->assertReservation($item, $f['a_item'], '60.000');
        $this->assertReservation($item, $f['b_item'], '60.000');
        $this->assertSame('76.00', $item->unit_price);
    }

    public function test_four_different_quarters_prorate_each_flavor_without_duplicating_dough(): void
    {
        $f = $this->differentBaseFixture(doughQuantities: [
            'a' => '305.000',
            'b' => '310.000',
            'c' => '315.000',
            'd' => '340.000',
        ]);
        $item = $this->add($f, [
            [$f['a'], 1, 4],
            [$f['b'], 1, 4],
            [$f['c'], 1, 4],
            [$f['d'], 1, 4],
        ], OrderType::DineIn);

        $this->assertReservation($item, $f['base_item'], '340.000');
        $this->assertSame(1, $item->reservations()->where('inventory_item_id', $f['base_item']->id)->count());
        $this->assertReservation($item, $f['cheese_item'], '180.000');
        $this->assertReservation($item, $f['tomato_item'], '25.000');
        $this->assertReservation($item, $f['white_sauce_item'], '25.000');
        $this->assertReservation($item, $f['a_item'], '30.000');
        $this->assertReservation($item, $f['b_item'], '30.000');
        $this->assertReservation($item, $f['c_item'], '30.000');
        $this->assertReservation($item, $f['d_item'], '30.000');
        $this->assertSame('85.00', $item->unit_price);
    }

    public function test_equal_highest_prices_use_the_first_selected_flavor_deterministically_without_duplicate_dough(): void
    {
        $f = $this->differentBaseFixture(doughQuantities: ['a' => '305.000', 'b' => '325.000']);
        $f['b']->update(['price' => '76.00']);
        $item = $this->add($f, [[$f['b'], 1, 2], [$f['a'], 1, 2]], OrderType::DineIn);

        $this->assertSame('76.00', $item->unit_price);
        $this->assertSame($f['b']->id, $item->product_variant_id);
        $this->assertReservation($item, $f['base_item'], '325.000');
        $this->assertSame(1, $item->reservations()->where('inventory_item_id', $f['base_item']->id)->count());
        $this->assertReservation($item, $f['tomato_item'], '50.000');
        $this->assertReservation($item, $f['white_sauce_item'], '50.000');
    }

    public function test_insufficient_stock_in_one_fractional_flavor_blocks_the_whole_fused_pizza(): void
    {
        $f = $this->differentBaseFixture('49.999');

        try {
            $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);
            $this->fail('The fused pizza must fail when one flavor lacks stock.');
        } catch (InsufficientStockException $exception) {
            $this->assertStringContainsString('Salsa de tomate', $exception->getMessage());
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
        }
    }

    public function test_single_flavor_keeps_its_full_recipe_with_structural_dough_once(): void
    {
        $f = $this->differentBaseFixture();
        $item = $this->add($f, [[$f['a'], 1, 1]], OrderType::DineIn);

        $this->assertReservation($item, $f['base_item'], '310.000');
        $this->assertReservation($item, $f['cheese_item'], '180.000');
        $this->assertReservation($item, $f['tomato_item'], '100.000');
        $this->assertReservation($item, $f['a_item'], '120.000');
        $this->assertSame('76.00', $item->unit_price);
    }

    public function test_fused_pizza_consumption_uses_combined_requirements_and_keeps_fefo(): void
    {
        $f = $this->differentBaseFixture(null);
        app(ApplyInventoryMovementAction::class)->execute(
            $f['company'],
            $f['branch'],
            $f['tomato_item'],
            InventoryMovementType::AdjustmentIn,
            '25.000',
            '1.000000',
            $f['owner'],
            reason: 'Lote próximo',
            batch: ['expires_at' => today()->addDay()],
        );
        app(ApplyInventoryMovementAction::class)->execute(
            $f['company'],
            $f['branch'],
            $f['tomato_item'],
            InventoryMovementType::AdjustmentIn,
            '40.000',
            '1.000000',
            $f['owner'],
            reason: 'Lote posterior',
            batch: ['expires_at' => today()->addDays(10)],
        );
        $table = RestaurantTable::factory()->for($f['branch'])->create(['company_id' => $f['company']->id]);
        $f['order'] = app(OpenTableOrderAction::class)->execute(
            $f['company'],
            $f['branch'],
            $table,
            $f['owner'],
            null,
            TableChargeMode::AtEnd,
        );
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);

        app(DispatchOrderToKitchenAction::class)->execute($item->order, $f['owner']);

        $batches = InventoryBatch::query()->where('inventory_item_id', $f['tomato_item']->id)
            ->orderBy('expires_at')->get();
        $this->assertSame('0.000', $batches[0]->quantity_remaining);
        $this->assertSame('15.000', $batches[1]->quantity_remaining);
        $movement = InventoryMovement::query()
            ->where('inventory_item_id', $f['tomato_item']->id)
            ->where('type', InventoryMovementType::OrderConsumption)
            ->sole();
        $this->assertSame('50.000', $movement->quantity);
        $this->assertSame($batches[0]->id, $movement->metadata['batch_allocations'][0]['batch_id']);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_item_id' => $f['base_item']->id,
            'type' => InventoryMovementType::OrderConsumption->value,
            'quantity' => 310,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_item_id' => $f['white_sauce_item']->id,
            'type' => InventoryMovementType::OrderConsumption->value,
            'quantity' => 50,
        ]);
    }

    public function test_cancelling_a_draft_fused_pizza_releases_its_combined_reservations_without_consumption(): void
    {
        $f = $this->differentBaseFixture();
        $item = $this->add($f, [[$f['a'], 1, 2], [$f['b'], 1, 2]], OrderType::DineIn);

        app(CancelOrderItemAction::class)->execute($item, $f['owner']);

        $this->assertSame(0, $item->reservations()->where('status', 'reserved')->count());
        $this->assertGreaterThan(0, $item->reservations()->where('status', 'released')->count());
        $this->assertDatabaseMissing('inventory_movements', [
            'reference_type' => $item::class,
            'reference_id' => $item->id,
            'type' => InventoryMovementType::OrderConsumption->value,
        ]);
    }

    /** @return array<string,mixed> */
    private function fixture(string $stock = '5000.000', bool $sharedTopping = false): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);
        $grams = Unit::factory()->for($company)->create(['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight]);
        $units = Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);
        [$base, $baseItem] = $this->ingredient($company, $grams, 'Masa');
        [$cheese, $cheeseItem] = $this->ingredient($company, $grams, 'Mozzarella');
        [$aIngredient, $aItem] = $this->ingredient($company, $grams, 'Topping A');
        [$bIngredient, $bItem] = $sharedTopping ? [$aIngredient, $aItem] : $this->ingredient($company, $grams, 'Topping B');
        [$cIngredient, $cItem] = $sharedTopping ? [$aIngredient, $aItem] : $this->ingredient($company, $grams, 'Topping C');
        [$dIngredient, $dItem] = $this->ingredient($company, $grams, 'Topping D');
        [, $boxItem] = $this->ingredient($company, $units, 'Caja familiar');
        foreach (collect([$baseItem, $cheeseItem, $aItem, $bItem, $cItem, $dItem, $boxItem])->unique('id') as $inventoryItem) {
            app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventoryItem, InventoryMovementType::AdjustmentIn, $stock, '1.000000', $owner, reason: 'Stock prueba');
        }

        $baseRecipe = [[$base, '450.000'], [$cheese, '300.000']];
        $a = $this->flavor($company, 'Sabor A', '75.00', $baseRecipe, [$aIngredient, $sharedTopping ? '100.000' : '120.000']);
        $b = $this->flavor($company, 'Sabor B', '80.00', $baseRecipe, [$bIngredient, $sharedTopping ? '100.000' : '120.000']);
        $c = $this->flavor($company, 'Sabor C', '78.00', $baseRecipe, [$cIngredient, $sharedTopping ? '100.000' : '120.000']);
        $d = $this->flavor($company, 'Sabor D', '85.00', $baseRecipe, [$dIngredient, '120.000']);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        return compact('company', 'branch', 'owner', 'grams', 'units', 'a', 'b', 'c', 'd', 'order') + [
            'base_item' => $baseItem,
            'cheese_item' => $cheeseItem,
            'a_item' => $aItem,
            'b_item' => $bItem,
            'c_item' => $cItem,
            'd_item' => $dItem,
            'box_item' => $boxItem,
        ];
    }

    /** @return array<string,mixed> */
    private function differentBaseFixture(?string $tomatoStock = '5000.000', array $doughQuantities = []): array
    {
        $f = $this->fixture();
        $doughQuantities = array_replace([
            'a' => '310.000',
            'b' => '310.000',
            'c' => '310.000',
            'd' => '310.000',
        ], $doughQuantities);
        $f['a']->product()->update(['name' => 'DULCE FUEGO']);
        $f['b']->product()->update(['name' => 'LA CHURA']);
        $f['a']->update(['price' => '76.00']);
        $f['b']->update(['price' => '71.00']);
        [$tomato, $tomatoItem] = $this->ingredient($f['company'], $f['grams'], 'Salsa de tomate');
        [$whiteSauce, $whiteSauceItem] = $this->ingredient($f['company'], $f['grams'], 'Salsa blanca');

        if ($tomatoStock !== null) {
            app(ApplyInventoryMovementAction::class)->execute(
                $f['company'],
                $f['branch'],
                $tomatoItem,
                InventoryMovementType::AdjustmentIn,
                $tomatoStock,
                '1.000000',
                $f['owner'],
                reason: 'Stock prueba',
            );
        }
        app(ApplyInventoryMovementAction::class)->execute(
            $f['company'],
            $f['branch'],
            $whiteSauceItem,
            InventoryMovementType::AdjustmentIn,
            '5000.000',
            '1.000000',
            $f['owner'],
            reason: 'Stock prueba',
        );

        $cheese = ['ingredient_id' => $f['cheese_item']->ingredient_id, 'component_type' => 'base', 'quantity' => '180.000'];
        $recipes = [
            'a' => [$tomato, $f['a_item']],
            'b' => [$whiteSauce, $f['b_item']],
        ];
        foreach ($recipes as $key => [$sauce, $toppingItem]) {
            $variant = $f[$key];
            app(UpdateRecipeAction::class)->execute($f['company'], $variant, [
                ['ingredient_id' => $f['base_item']->ingredient_id, 'component_type' => 'base', 'quantity' => $doughQuantities[$key]],
                ['ingredient_id' => $sauce->id, 'component_type' => 'base', 'quantity' => '100.000'],
                $cheese,
                ['ingredient_id' => $toppingItem->ingredient_id, 'component_type' => 'topping', 'quantity' => '120.000'],
            ], 'Receta '.$variant->product->name);
        }
        foreach (['c', 'd'] as $key) {
            $variant = $f[$key];
            $toppingItem = $f[$key.'_item'];
            app(UpdateRecipeAction::class)->execute($f['company'], $variant, [
                ['ingredient_id' => $f['base_item']->ingredient_id, 'component_type' => 'base', 'quantity' => $doughQuantities[$key]],
                $cheese,
                ['ingredient_id' => $toppingItem->ingredient_id, 'component_type' => 'topping', 'quantity' => '120.000'],
            ], 'Receta '.$variant->product->name);
        }

        return $f + [
            'tomato_item' => $tomatoItem,
            'white_sauce_item' => $whiteSauceItem,
        ];
    }

    private function flavor(Company $company, string $name, string $price, array $base, array $topping): ProductVariant
    {
        $product = Product::factory()->for($company)->create(['name' => $name, 'type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['name' => 'Familiar', 'size_key' => 'familiar', 'price' => $price]);
        $items = collect($base)->map(fn (array $part): array => ['ingredient_id' => $part[0]->id, 'component_type' => 'base', 'quantity' => $part[1]])
            ->push(['ingredient_id' => $topping[0]->id, 'component_type' => 'topping', 'quantity' => $topping[1]])->all();
        app(UpdateRecipeAction::class)->execute($company, $variant, $items, "Receta {$name}");

        return $variant;
    }

    private function ingredient(Company $company, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'ingredient_id' => $ingredient->id, 'name' => $name, 'is_active' => true]);

        return [$ingredient, $item];
    }

    private function add(array $f, array $sections, OrderType $fulfillment, array $modifiers = [])
    {
        return app(AddConfiguredPizzaAction::class)->execute($f['order'], $this->sections($sections), '1.000', $f['owner'], $fulfillment, $this->modifiers($modifiers));
    }

    private function sections(array $sections): array
    {
        return array_map(fn (array $section): array => ['product_variant' => $section[0], 'fraction_numerator' => $section[1], 'fraction_denominator' => $section[2]], $sections);
    }

    private function modifiers(array $modifiers): array
    {
        return array_map(fn (array $modifier): array => ['option' => $modifier[0], 'section_position' => $modifier[1]], $modifiers);
    }

    private function extra(array $f, string $price, string $quantity): ModifierOption
    {
        $modifier = ProductModifier::query()->create(['company_id' => $f['company']->id, 'name' => 'Extras', 'is_active' => true]);

        return ModifierOption::query()->create(['company_id' => $f['company']->id, 'product_modifier_id' => $modifier->id, 'name' => 'Extra queso', 'type' => ModifierOptionType::Add, 'price_delta' => $price, 'inventory_item_id' => $f['cheese_item']->id, 'ingredient_id' => $f['cheese_item']->ingredient_id, 'quantity' => $quantity, 'unit_id' => $f['grams']->id, 'is_active' => true]);
    }

    private function remove(array $f, InventoryItem $inventoryItem, string $name): ModifierOption
    {
        $modifier = ProductModifier::query()->firstOrCreate(['company_id' => $f['company']->id, 'name' => 'Removidos'], ['is_active' => true]);

        return ModifierOption::query()->create(['company_id' => $f['company']->id, 'product_modifier_id' => $modifier->id, 'name' => $name, 'type' => ModifierOptionType::Remove, 'price_delta' => '0.00', 'inventory_item_id' => $inventoryItem->id, 'ingredient_id' => $inventoryItem->ingredient_id, 'unit_id' => $inventoryItem->unit_id, 'is_active' => true]);
    }

    private function packaging(array $f): void
    {
        PackagingRule::query()->create(['company_id' => $f['company']->id, 'size_key' => 'familiar', 'fulfillment_type' => OrderType::Takeaway, 'inventory_item_id' => $f['box_item']->id, 'quantity' => '1.000']);
    }

    private function assertReservation($item, InventoryItem $inventoryItem, string $quantity): void
    {
        $this->assertSame($quantity, InventoryReservation::query()->where('order_item_id', $item->id)->where('inventory_item_id', $inventoryItem->id)->where('status', 'reserved')->value('quantity'));
    }
}
