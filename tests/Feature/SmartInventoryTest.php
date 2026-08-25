<?php

namespace Tests\Feature;

use App\Actions\ApplyInventoryMovementAction;
use App\Actions\PostPurchaseAction;
use App\Actions\RegisterExpiredBatchWasteAction;
use App\Actions\RegisterOpeningStockAction;
use App\Actions\RegisterWasteAction;
use App\Actions\ReverseInventoryMovementAction;
use App\Actions\UpdateMinimumStockAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InventoryBatchStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStockStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryBatchConsistencyService;
use App\Services\InventoryBatchExpirationService;
use App\Services\InventoryStockStatusService;
use App\Services\RecipeAvailabilityService;
use App\Services\SellableAvailabilityService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_owner_can_open_inventory_detail(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $this->entry($company, $branch, $item, $owner, '2.000', $this->expiredDate());

        $this->actingAs($owner)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get(route('inventory.show', $item->ulid))
            ->assertOk()
            ->assertSee('Stock físico')
            ->assertSee('Stock vencido')
            ->assertSee('Reservado')
            ->assertSee('Disponible')
            ->assertSee('Stock mínimo')
            ->assertSee('Retirar vencido')
            ->assertSee('Movimientos recientes');
    }

    public function test_minimum_stock_is_configured_per_branch(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();

        app(UpdateMinimumStockAction::class)->execute($company, $branch, $item, '3.500', $owner);
        app(UpdateMinimumStockAction::class)->execute($company, $otherBranch, $item, null, $owner);

        $this->assertDatabaseHas('inventory_stocks', [
            'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'minimum_quantity' => 3.5,
        ]);
        $this->assertNull(InventoryStock::query()->where('branch_id', $otherBranch->id)->value('minimum_quantity'));
    }

    public function test_stock_status_supports_normal_low_and_out_with_future_reservations(): void
    {
        $service = app(InventoryStockStatusService::class);

        $this->assertSame(InventoryStockStatus::Normal, $service->status('8.000', '3.000'));
        $this->assertSame(InventoryStockStatus::Low, $service->status('3.000', '3.000'));
        $this->assertSame(InventoryStockStatus::Out, $service->status('0.000', '3.000'));
        $this->assertSame('6.000', $service->availableQuantity('8.000', '2.000'));
    }

    public function test_batch_expiration_statuses_cover_expired_soon_ok_and_no_expiration(): void
    {
        $service = app(InventoryBatchExpirationService::class);
        $today = CarbonImmutable::parse('2026-08-23');

        $this->assertSame(InventoryBatchStatus::Expired, $service->status($this->batchModel('2026-08-22'), $today));
        $this->assertSame(InventoryBatchStatus::ExpiringSoon, $service->status($this->batchModel('2026-08-27'), $today));
        $this->assertSame(InventoryBatchStatus::Ok, $service->status($this->batchModel('2026-09-15'), $today));
        $this->assertSame(InventoryBatchStatus::NoExpiration, $service->status($this->batchModel(null), $today));
    }

    public function test_posting_purchase_creates_batch_with_optional_expiration(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();
        $purchase = Purchase::factory()->for($company)->for($branch)->create([
            'created_by' => $owner->id,
            'status' => PurchaseStatus::Draft,
            'purchased_at' => '2026-08-23 10:00:00',
        ]);
        $purchaseItem = PurchaseItem::factory()->for($company)->for($purchase)->create([
            'inventory_item_id' => $item->id,
            'input_unit_id' => $unit->id,
            'quantity' => '10.000',
            'base_quantity' => '10.000',
            'unit_cost' => '2.500000',
            'total_cost' => '25.000000',
            'expires_at' => '2026-09-01',
        ]);

        app(PostPurchaseAction::class)->execute($purchase, $owner);

        $batch = InventoryBatch::query()->where('purchase_item_id', $purchaseItem->id)->firstOrFail();
        $this->assertSame('10.000', $batch->quantity_remaining);
        $this->assertSame('2.500000', $batch->unit_cost);
        $this->assertSame('2026-09-01', $batch->expires_at->format('Y-m-d'));
    }

    public function test_opening_stock_creates_batch_without_expiration(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();

        $movement = app(RegisterOpeningStockAction::class)->execute(
            $company, $branch, $item, '12.000', $unit, '1.250000', $owner,
            CarbonImmutable::parse('2026-08-23 09:00'),
        );

        $batch = InventoryBatch::query()->where('inventory_movement_id', $movement->id)->firstOrFail();
        $this->assertSame('12.000', $batch->quantity_received);
        $this->assertSame('12.000', $batch->quantity_remaining);
        $this->assertNull($batch->expires_at);
    }

    public function test_fefo_consumes_earliest_expiration_then_later_and_no_expiration(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();
        $late = $this->entry($company, $branch, $item, $owner, '5.000', $this->futureDate(20));
        $soon = $this->entry($company, $branch, $item, $owner, '4.000', $this->futureDate(7));
        $withoutExpiration = $this->entry($company, $branch, $item, $owner, '3.000', null);

        $movement = app(RegisterWasteAction::class)->execute(
            $company, $branch, $item, '6.000', $unit, 'Control de calidad', $owner,
        );

        $this->assertSame('0.000', $soon->inventoryBatch->refresh()->quantity_remaining);
        $this->assertSame('3.000', $late->inventoryBatch->refresh()->quantity_remaining);
        $this->assertSame('3.000', $withoutExpiration->inventoryBatch->refresh()->quantity_remaining);
        $this->assertCount(2, $movement->metadata['batch_allocations']);
    }

    public function test_fefo_supports_partial_batch_consumption(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();
        $entry = $this->entry($company, $branch, $item, $owner, '10.000', $this->futureDate(7));

        app(RegisterWasteAction::class)->execute($company, $branch, $item, '2.500', $unit, 'Muestra', $owner);

        $this->assertSame('7.500', $entry->inventoryBatch->refresh()->quantity_remaining);
    }

    public function test_fefo_never_consumes_batches_from_another_branch(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();
        $local = $this->entry($company, $branch, $item, $owner, '5.000', $this->futureDate(10));
        $other = $this->entry($company, $otherBranch, $item, $owner, '5.000', $this->futureDate(2));

        app(RegisterWasteAction::class)->execute($company, $branch, $item, '2.000', $unit, 'Merma local', $owner);

        $this->assertSame('3.000', $local->inventoryBatch->refresh()->quantity_remaining);
        $this->assertSame('5.000', $other->inventoryBatch->refresh()->quantity_remaining);
    }

    public function test_batch_decimals_remain_exact_strings(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $entry = app(ApplyInventoryMovementAction::class)->execute(
            $company,
            $branch,
            $item,
            InventoryMovementType::AdjustmentIn,
            '0.333',
            '0.123456',
            $owner,
            reason: 'Precisión decimal',
        );

        $batch = $entry->inventoryBatch;
        $this->assertSame('0.333', $batch->quantity_remaining);
        $this->assertSame('0.123456', $batch->unit_cost);
    }

    public function test_expired_quantity_remains_physical_but_is_not_available(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $expired = $this->entry($company, $branch, $item, $owner, '2.000', $this->expiredDate());
        $this->entry($company, $branch, $item, $owner, '8.000', $this->futureDate(5));
        $stock = InventoryStock::query()->where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)->firstOrFail();

        $availability = app(InventoryAvailabilityService::class)->forStock($stock);

        $this->assertSame('10.000', $availability->physicalQuantity);
        $this->assertSame('2.000', $availability->expiredQuantity);
        $this->assertSame('8.000', $availability->availableQuantity);
        $this->assertSame('10.000', $stock->quantity);
        $this->assertSame('2.000', $expired->inventoryBatch->refresh()->quantity_remaining);
    }

    public function test_normal_fefo_ignores_expired_and_uses_valid_then_no_expiration(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();
        $expired = $this->entry($company, $branch, $item, $owner, '3.000', $this->expiredDate());
        $valid = $this->entry($company, $branch, $item, $owner, '4.000', $this->futureDate(2));
        $withoutExpiration = $this->entry($company, $branch, $item, $owner, '2.000', null);

        app(RegisterWasteAction::class)->execute(
            $company, $branch, $item, '5.000', $unit, 'Merma de stock utilizable', $owner,
        );

        $this->assertSame('3.000', $expired->inventoryBatch->refresh()->quantity_remaining);
        $this->assertSame('0.000', $valid->inventoryBatch->refresh()->quantity_remaining);
        $this->assertSame('1.000', $withoutExpiration->inventoryBatch->refresh()->quantity_remaining);
    }

    public function test_expired_stock_does_not_increase_recipe_availability(): void
    {
        [$company, $branch, , $owner, $unit] = $this->context();
        [$ingredient, $item] = $this->ingredientItem($company, $unit, 'Mozzarella');
        $variant = $this->recipeVariant($company, [
            ['ingredient_id' => $ingredient->id, 'quantity' => '300.000'],
        ]);
        $this->entry($company, $branch, $item, $owner, '2400.000', $this->futureDate(5));
        $this->entry($company, $branch, $item, $owner, '600.000', $this->expiredDate());

        $result = app(RecipeAvailabilityService::class)->calculate($variant, $branch);

        $this->assertSame(8, $result->producibleQuantity);
        $this->assertSame('2400.000', $result->limitingIngredients[0]['available']);
    }

    public function test_direct_sale_availability_excludes_expired_units(): void
    {
        [$company, $branch, , $owner, $unit] = $this->context();
        $product = Product::factory()->for($company)->create(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create();
        $item = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'product_variant_id' => $variant->id,
            'name' => 'Bebida con caducidad',
            'is_active' => true,
        ]);
        $this->entry($company, $branch, $item, $owner, '8.000', $this->futureDate(5));
        $this->entry($company, $branch, $item, $owner, '2.000', $this->expiredDate());

        $result = app(SellableAvailabilityService::class)->calculate($variant, $branch);

        $this->assertSame('direct', $result->mode);
        $this->assertSame('8.000', $result->availableQuantity);
    }

    public function test_expired_batch_can_be_retired_explicitly_with_ledger_consistency(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $entry = $this->entry($company, $branch, $item, $owner, '2.000', $this->expiredDate());

        $movement = app(RegisterExpiredBatchWasteAction::class)->execute(
            $company,
            $branch,
            $entry->inventoryBatch,
            '1.500',
            'Retiro por caducidad',
            $owner,
        );

        $stock = InventoryStock::query()->where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)->firstOrFail();
        $remaining = InventoryBatch::query()->where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)->get()
            ->reduce(
                fn (BigDecimal $total, InventoryBatch $batch): BigDecimal => $total->plus($batch->quantity_remaining),
                BigDecimal::zero(),
            );

        $this->assertSame(InventoryMovementType::Waste, $movement->type);
        $this->assertTrue($movement->metadata['administrative_expired_disposal']);
        $this->assertSame('0.500', $stock->quantity);
        $this->assertSame('0.500', $entry->inventoryBatch->refresh()->quantity_remaining);
        $this->assertSame('0.500', (string) $remaining->toScale(3));
    }

    public function test_non_expired_batch_cannot_use_expired_disposal_operation(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $entry = $this->entry($company, $branch, $item, $owner, '2.000', $this->futureDate(5));

        $this->expectException(DomainException::class);
        app(RegisterExpiredBatchWasteAction::class)->execute(
            $company, $branch, $entry->inventoryBatch, '1.000', 'No corresponde', $owner,
        );
    }

    public function test_insufficient_stock_rolls_back_batch_and_stock_changes(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->context();
        $entry = $this->entry($company, $branch, $item, $owner, '3.000', '2026-08-30');

        try {
            app(RegisterWasteAction::class)->execute($company, $branch, $item, '4.000', $unit, 'Exceso', $owner);
            $this->fail('The inventory operation should fail.');
        } catch (InsufficientStockException) {
            $this->assertSame('3.000', $entry->inventoryBatch->refresh()->quantity_remaining);
            $this->assertSame('3.000', InventoryStock::query()->where('inventory_item_id', $item->id)->value('quantity'));
        }
    }

    public function test_historical_stock_gets_an_idempotent_transition_batch(): void
    {
        [$company, $branch, $item] = $this->context();
        $stock = InventoryStock::factory()->for($company)->for($branch)->create([
            'inventory_item_id' => $item->id,
            'quantity' => '8.000',
            'average_cost' => '1.500000',
        ]);

        $service = app(InventoryBatchConsistencyService::class);
        $service->ensureCoverage($stock);
        $service->ensureCoverage($stock);

        $this->assertDatabaseCount('inventory_batches', 1);
        $this->assertDatabaseHas('inventory_batches', [
            'transition_stock_id' => $stock->id,
            'quantity_remaining' => 8,
        ]);
    }

    public function test_historical_incoming_movement_can_be_reversed_from_its_transition_batch(): void
    {
        [$company, $branch, $item, $owner] = $this->context();
        $movement = InventoryMovement::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'type' => InventoryMovementType::Opening,
            'quantity' => '5.000',
            'unit_cost' => '2.000000',
            'total_cost' => '10.000000',
            'occurred_at' => now(),
            'created_by' => $owner->id,
        ]);
        $stock = InventoryStock::factory()->for($company)->for($branch)->create([
            'inventory_item_id' => $item->id,
            'quantity' => '5.000',
            'average_cost' => '2.000000',
        ]);
        app(InventoryBatchConsistencyService::class)->ensureCoverage($stock);

        app(ReverseInventoryMovementAction::class)->execute($movement, $owner, 'Corrección histórica');

        $this->assertSame('0.000', $stock->refresh()->quantity);
        $this->assertSame('0.000', InventoryBatch::query()
            ->where('transition_stock_id', $stock->id)->value('quantity_remaining'));
    }

    public function test_batches_reject_cross_company_and_cross_branch_context(): void
    {
        [$company, $branch, $item] = $this->context();
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();

        $this->expectException(DomainException::class);
        InventoryBatch::query()->create([
            'company_id' => $company->id,
            'branch_id' => $otherBranch->id,
            'inventory_item_id' => $item->id,
            'quantity_received' => '1.000',
            'quantity_remaining' => '1.000',
            'unit_cost' => '1.000000',
            'received_at' => now(),
        ]);
    }

    public function test_recipe_availability_returns_quantity_and_limiting_ingredient(): void
    {
        [$company, $branch, , $owner, $unit] = $this->context();
        [$firstIngredient, $firstItem] = $this->ingredientItem($company, $unit, 'Masa');
        [$secondIngredient, $secondItem] = $this->ingredientItem($company, $unit, 'Piña');
        $variant = $this->recipeVariant($company, [
            ['ingredient_id' => $firstIngredient->id, 'quantity' => '100.000'],
            ['ingredient_id' => $secondIngredient->id, 'quantity' => '120.000'],
        ]);
        $this->open($company, $branch, $firstItem, $unit, $owner, '2000.000');
        $this->open($company, $branch, $secondItem, $unit, $owner, '840.000');

        $result = app(RecipeAvailabilityService::class)->calculate($variant, $branch);

        $this->assertSame(7, $result->producibleQuantity);
        $this->assertSame('Piña', $result->limitingIngredients[0]['ingredient']);
        $this->assertSame('840.000', $result->limitingIngredients[0]['available']);
        $this->assertSame('120.000', $result->limitingIngredients[0]['required']);
        $this->assertSame('120.000', $result->limitingIngredients[0]['shortage_for_next']);
    }

    public function test_recipe_availability_is_zero_when_an_ingredient_has_no_stock(): void
    {
        [$company, $branch, , , $unit] = $this->context();
        [$ingredient] = $this->ingredientItem($company, $unit, 'Salsa');
        $variant = $this->recipeVariant($company, [['ingredient_id' => $ingredient->id, 'quantity' => '50.000']]);

        $result = app(RecipeAvailabilityService::class)->calculate($variant, $branch);

        $this->assertSame(0, $result->producibleQuantity);
        $this->assertSame('50.000', $result->limitingIngredients[0]['shortage_for_next']);
    }

    public function test_direct_sale_variant_uses_inventory_stock_without_recipe(): void
    {
        [$company, $branch, , $owner, $unit] = $this->context();
        $product = Product::factory()->for($company)->create(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create();
        $item = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'product_variant_id' => $variant->id,
            'name' => 'Bebida directa',
            'is_active' => true,
        ]);
        $this->open($company, $branch, $item, $unit, $owner, '24.000');

        $result = app(SellableAvailabilityService::class)->calculate($variant, $branch);

        $this->assertSame('direct', $result->mode);
        $this->assertSame('24.000', $result->availableQuantity);
    }

    public function test_inventory_seeder_remains_idempotent_with_batches(): void
    {
        $this->seed(DatabaseSeeder::class);
        $count = InventoryBatch::query()->count();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($count, InventoryBatch::query()->count());
        $this->assertSame(10, $count);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad de prueba', 'symbol' => 'u', 'type' => UnitType::Unit,
        ]);
        [, $item] = $this->ingredientItem($company, $unit, 'Ingrediente principal');

        return [$company, $branch, $item, $owner, $unit];
    }

    private function ingredientItem(Company $company, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);
        $item = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'name' => $name,
            'is_active' => true,
        ]);

        return [$ingredient, $item];
    }

    private function entry(
        Company $company,
        Branch $branch,
        InventoryItem $item,
        User $owner,
        string $quantity,
        ?string $expiresAt,
    ) {
        return app(ApplyInventoryMovementAction::class)->execute(
            $company,
            $branch,
            $item,
            InventoryMovementType::AdjustmentIn,
            $quantity,
            '1.000000',
            $owner,
            reason: 'Ingreso de prueba',
            batch: ['expires_at' => $expiresAt ? CarbonImmutable::parse($expiresAt) : null],
        )->load('inventoryBatch');
    }

    private function open(
        Company $company,
        Branch $branch,
        InventoryItem $item,
        Unit $unit,
        User $owner,
        string $quantity,
    ): void {
        app(RegisterOpeningStockAction::class)->execute(
            $company, $branch, $item, $quantity, $unit, '1.000000', $owner,
        );
    }

    private function recipeVariant(Company $company, array $items): ProductVariant
    {
        $product = Product::factory()->for($company)->create(['type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create();
        app(UpdateRecipeAction::class)->execute($company, $variant, $items, 'Receta de prueba');

        return $variant;
    }

    private function batchModel(?string $expiresAt): InventoryBatch
    {
        $batch = new InventoryBatch;
        $batch->expires_at = $expiresAt;

        return $batch;
    }

    private function futureDate(int $days): string
    {
        return CarbonImmutable::now(config('inventory.timezone', 'America/La_Paz'))
            ->addDays($days)->toDateString();
    }

    private function expiredDate(): string
    {
        return CarbonImmutable::now(config('inventory.timezone', 'America/La_Paz'))
            ->subDay()->toDateString();
    }
}
