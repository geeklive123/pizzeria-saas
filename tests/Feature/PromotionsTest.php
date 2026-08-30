<?php

namespace Tests\Feature;

use App\Actions\AddPromotionToOrderAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\SavePromotionAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Promotion;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chop_promotion_consumes_exactly_two_units_once(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $chop = $this->inventoryItem($company, $unit, 'CHOP DE CERVEZA');
        $this->stock($company, $branch, $chop, $owner, '5.000');
        $promotion = $this->promotion($company, $owner, '2x1 CHOP DE CERVEZA', '25.00', [[$chop, '2.000']]);
        $order = $this->releasedTableOrder($company, $branch, $owner);
        $item = app(AddPromotionToOrderAction::class)->execute($order, $promotion, '1.000', $owner);

        $this->assertSame('2.000', $item->reservations()->firstOrFail()->quantity);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order->refresh(), $owner);

        $this->assertSame('3.000', $this->stockQuantity($branch, $chop));
        $this->assertSame(InventoryReservationStatus::Consumed, $item->reservations()->firstOrFail()->status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption)->count());
    }

    public function test_red_wine_promotion_consumes_exactly_half_a_bottle(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $wine = $this->inventoryItem($company, $unit, 'VINO TINTO TERRUÑO');
        $this->stock($company, $branch, $wine, $owner, '2.000');
        $promotion = $this->promotion($company, $owner, '2x1 COPA DE VINO TINTO', '20.00', [[$wine, '0.500']]);
        $order = $this->releasedTableOrder($company, $branch, $owner);
        $item = app(AddPromotionToOrderAction::class)->execute($order, $promotion, '1.000', $owner);

        $this->assertSame('0.500', $item->reservations()->firstOrFail()->quantity);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertSame('1.500', $this->stockQuantity($branch, $wine));
    }

    public function test_rose_wine_promotion_consumes_exactly_half_a_bottle(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $wine = $this->inventoryItem($company, $unit, 'VINO SANTA ANA ROSADO SEMI DULCE');
        $this->stock($company, $branch, $wine, $owner, '1.000');
        $promotion = $this->promotion($company, $owner, '2x1 COPA DE VINO ROSADO', '25.00', [[$wine, '0.500']]);
        $order = $this->releasedTableOrder($company, $branch, $owner);
        $item = app(AddPromotionToOrderAction::class)->execute($order, $promotion, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertSame('0.500', $item->reservations()->firstOrFail()->quantity);
        $this->assertSame('0.500', $this->stockQuantity($branch, $wine));
    }

    public function test_server_controls_price_and_snapshot_survives_later_promotion_changes(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $wine = $this->inventoryItem($company, $unit, 'VINO TINTO TERRUÑO');
        $this->stock($company, $branch, $wine, $owner, '2.000');
        $promotion = $this->promotion($company, $owner, '2x1 COPA DE VINO TINTO', '20.00', [[$wine, '0.500']]);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        $this->actingInContext($owner, $company, $branch)->post(route('orders.promotions.store', $order->ulid), [
            'promotion' => $promotion->ulid,
            'quantity' => '1.000',
            'price' => '0.01',
        ])->assertRedirect();
        $item = $order->items()->firstOrFail();
        $this->assertSame('20.00', $item->unit_price);
        $this->assertSame('20.00', $item->line_total);

        $this->savePromotion($company, $owner, 'PROMO RENOMBRADA', '99.00', [[$wine, '1.000']], $promotion);
        $snapshot = $item->refresh()->configuration_snapshot;
        $this->assertSame('2x1 COPA DE VINO TINTO', $snapshot['promotion']['name']);
        $this->assertSame('20.00', $snapshot['promotion']['unit_price']);
        $this->assertSame('0.500', $snapshot['components'][0]['quantity_applied']);
        $this->assertSame($wine->ulid, $snapshot['components'][0]['inventory_item_ulid']);
    }

    public function test_insufficient_or_expired_stock_rejects_promotion_without_partial_reservation(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $chop = $this->inventoryItem($company, $unit, 'CHOP DE CERVEZA');
        $this->stock($company, $branch, $chop, $owner, '1.000');
        $promotion = $this->promotion($company, $owner, '2x1 CHOP DE CERVEZA', '25.00', [[$chop, '2.000']]);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        try {
            app(AddPromotionToOrderAction::class)->execute($order, $promotion, '1.000', $owner);
            $this->fail('Stock insuficiente debía rechazar la promoción.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
        }

        $expired = $this->inventoryItem($company, $unit, 'VINO VENCIDO');
        $this->stock($company, $branch, $expired, $owner, '1.000', CarbonImmutable::yesterday());
        $expiredPromotion = $this->promotion($company, $owner, '2x1 VINO VENCIDO', '20.00', [[$expired, '0.500']]);
        try {
            app(AddPromotionToOrderAction::class)->execute($order, $expiredPromotion, '1.000', $owner);
            $this->fail('Stock vencido debía rechazar la promoción.');
        } catch (InsufficientStockException) {
            $this->assertSame('1.000', $this->stockQuantity($branch, $expired));
            $this->assertDatabaseCount('inventory_reservations', 0);
        }
    }

    public function test_single_reservation_is_released_on_cancellation(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $chop = $this->inventoryItem($company, $unit, 'CHOP DE CERVEZA');
        $this->stock($company, $branch, $chop, $owner, '4.000');
        $promotion = $this->promotion($company, $owner, '2x1 CHOP DE CERVEZA', '25.00', [[$chop, '2.000']]);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddPromotionToOrderAction::class)->execute($order, $promotion, '1.000', $owner);

        $this->assertSame(1, $item->reservations()->count());
        app(CancelOrderItemAction::class)->execute($item, $owner);

        $this->assertSame(InventoryReservationStatus::Released, $item->reservations()->firstOrFail()->status);
        $this->assertSame('4.000', $this->stockQuantity($branch, $chop));
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_inactive_promotion_is_hidden_and_other_company_cannot_use_it(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $chop = $this->inventoryItem($company, $unit, 'CHOP DE CERVEZA');
        $this->stock($company, $branch, $chop, $owner, '4.000');
        $promotion = $this->promotion($company, $owner, '2x1 CHOP DE CERVEZA', '25.00', [[$chop, '2.000']], false);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        $this->actingInContext($owner, $company, $branch)->get(route('orders.show', $order->ulid))
            ->assertOk()->assertDontSee('2x1 CHOP DE CERVEZA')->assertDontSee('data-pos-category="promotions"', false);

        [$otherCompany, $otherBranch, $otherOwner] = $this->context();
        $otherOrder = app(CreateTakeawayOrderAction::class)->execute($otherCompany, $otherBranch, $otherOwner);
        $this->expectException(DomainException::class);
        app(AddPromotionToOrderAction::class)->execute($otherOrder, $promotion, '1.000', $otherOwner);
    }

    public function test_pos_shows_promotions_category_real_availability_and_exact_decimal_component(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $wine = $this->inventoryItem($company, $unit, 'VINO TINTO TERRUÑO');
        $this->stock($company, $branch, $wine, $owner, '1.250');
        $this->promotion($company, $owner, '2x1 COPA DE VINO TINTO', '20.00', [[$wine, '0.500']]);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        $this->actingInContext($owner, $company, $branch)->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('data-pos-category="promotions"', false)
            ->assertSee('Promociones')
            ->assertSee('2x1 COPA DE VINO TINTO')
            ->assertSee('Disponibles: 2')
            ->assertSee('0,5 u');
    }

    private function releasedTableOrder(Company $company, Branch $branch, User $owner)
    {
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);

        return app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create(['name' => 'Botella o unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);

        return [$company, $branch, $owner, $unit];
    }

    private function inventoryItem(Company $company, Unit $unit, string $name): InventoryItem
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);

        return InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $unit->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function promotion(Company $company, User $owner, string $name, string $price, array $components, bool $active = true): Promotion
    {
        return $this->savePromotion($company, $owner, $name, $price, $components, null, $active);
    }

    private function savePromotion(Company $company, User $owner, string $name, string $price, array $components, ?Promotion $promotion = null, bool $active = true): Promotion
    {
        return app(SavePromotionAction::class)->execute($company, [
            'name' => $name,
            'description' => null,
            'price' => $price,
            'is_active' => $active,
            'starts_at' => null,
            'ends_at' => null,
            'components' => collect($components)->map(fn (array $component): array => [
                'inventory_item_ulid' => $component[0]->ulid,
                'quantity' => $component[1],
            ])->all(),
        ], $owner, $promotion);
    }

    private function stock(Company $company, Branch $branch, InventoryItem $item, User $owner, string $quantity, ?CarbonImmutable $expiresAt = null): void
    {
        app(ApplyInventoryMovementAction::class)->execute(
            $company, $branch, $item, InventoryMovementType::AdjustmentIn, $quantity, '1.000000', $owner,
            reason: 'Stock promoción', batch: ['expires_at' => $expiresAt],
        );
    }

    private function stockQuantity(Branch $branch, InventoryItem $item): string
    {
        return InventoryStock::query()->where('branch_id', $branch->getKey())
            ->where('inventory_item_id', $item->getKey())->value('quantity');
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->getKey(),
            'active_branch_id' => $branch->getKey(),
        ]);
    }
}
