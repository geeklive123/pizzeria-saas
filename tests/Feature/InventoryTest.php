<?php

namespace Tests\Feature;

use App\Actions\PostPurchaseAction;
use App\Actions\RegisterInventoryAdjustmentAction;
use App\Actions\RegisterOpeningStockAction;
use App\Actions\RegisterWasteAction;
use App\Actions\ReverseInventoryMovementAction;
use App\Actions\ReversePurchaseAction;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseStatus;
use App\Enums\UnitType;
use App\Exceptions\ImmutableInventoryMovementException;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitConversionService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_increases_balance_and_normalizes_cost(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $kilogram = $this->unit($company, 'Kilogramo', 'kg', UnitType::Weight);

        app(RegisterOpeningStockAction::class)->execute(
            $company,
            $branch,
            $ingredient,
            '2.000',
            $kilogram,
            '40.000000',
            $owner,
        );

        $stock = $this->stock($company, $branch, $ingredient);
        $this->assertSame('2000.000', $stock->quantity);
        $this->assertSame('0.040000', $stock->average_cost);
        $this->assertDatabaseHas('inventory_movements', [
            'type' => InventoryMovementType::Opening->value,
            'quantity' => 2000,
        ]);
        $this->assertSame('g', $gram->symbol);
    }

    public function test_posted_purchase_increases_stock_and_converts_kilograms_to_grams(): void
    {
        [$company, $branch, $ingredient, $owner] = $this->inventoryContext();
        $kilogram = $this->unit($company, 'Kilogramo', 'kg', UnitType::Weight);
        $purchase = $this->purchase($company, $branch, $ingredient, $kilogram, $owner, '2.000', '50.000000');

        app(PostPurchaseAction::class)->execute($purchase, $owner);

        $stock = $this->stock($company, $branch, $ingredient);
        $this->assertSame('2000.000', $stock->quantity);
        $this->assertSame('0.050000', $stock->average_cost);
        $this->assertSame(PurchaseStatus::Posted, $purchase->refresh()->status);
        $this->assertSame('2000.000', $purchase->items()->firstOrFail()->base_quantity);
    }

    public function test_draft_purchase_does_not_affect_inventory(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->purchase($company, $branch, $ingredient, $gram, $owner, '10.000', '1.000000');

        $this->assertDatabaseCount('inventory_stocks', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_posted_purchase_recalculates_weighted_average_cost(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '1000.000', '0.040000');
        $purchase = $this->purchase($company, $branch, $ingredient, $gram, $owner, '1000.000', '0.050000');

        app(PostPurchaseAction::class)->execute($purchase, $owner);

        $stock = $this->stock($company, $branch, $ingredient);
        $this->assertSame('2000.000', $stock->quantity);
        $this->assertSame('0.045000', $stock->average_cost);
    }

    public function test_liters_are_converted_to_milliliters(): void
    {
        $company = Company::factory()->create();
        $liter = $this->unit($company, 'Litro', 'L', UnitType::Volume);
        $milliliter = $this->unit($company, 'Mililitro', 'ml', UnitType::Volume);

        $result = app(UnitConversionService::class)->convert('1.500', $liter, $milliliter);

        $this->assertSame('1500.000', $result);
    }

    public function test_incompatible_unit_conversion_is_rejected(): void
    {
        $company = Company::factory()->create();
        $gram = $this->unit($company, 'Gramo', 'g', UnitType::Weight);
        $unit = $this->unit($company, 'Unidad', 'u', UnitType::Unit);

        $this->expectException(DomainException::class);

        app(UnitConversionService::class)->convert('1.000', $gram, $unit);
    }

    public function test_waste_decreases_stock_without_changing_average_cost(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '1000.000', '0.040000');

        app(RegisterWasteAction::class)->execute(
            $company,
            $branch,
            $ingredient,
            '250.000',
            $gram,
            'vencido',
            $owner,
        );

        $stock = $this->stock($company, $branch, $ingredient);
        $this->assertSame('750.000', $stock->quantity);
        $this->assertSame('0.040000', $stock->average_cost);
    }

    public function test_positive_adjustment_increases_stock(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '100.000', '0.010000');

        app(RegisterInventoryAdjustmentAction::class)->execute(
            $company,
            $branch,
            $ingredient,
            InventoryMovementType::AdjustmentIn,
            '20.000',
            $gram,
            'conteo físico',
            $owner,
        );

        $this->assertSame('120.000', $this->stock($company, $branch, $ingredient)->quantity);
    }

    public function test_negative_adjustment_decreases_stock(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '100.000', '0.010000');

        app(RegisterInventoryAdjustmentAction::class)->execute(
            $company,
            $branch,
            $ingredient,
            InventoryMovementType::AdjustmentOut,
            '25.000',
            $gram,
            'conteo físico',
            $owner,
        );

        $this->assertSame('75.000', $this->stock($company, $branch, $ingredient)->quantity);
    }

    public function test_outgoing_movement_cannot_create_negative_stock(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '10.000', '1.000000');

        $this->expectException(InsufficientStockException::class);

        app(RegisterWasteAction::class)->execute(
            $company,
            $branch,
            $ingredient,
            '11.000',
            $gram,
            'derrame',
            $owner,
        );
    }

    public function test_user_cannot_modify_another_companies_stock(): void
    {
        [$companyA, , , $ownerA] = $this->inventoryContext();
        [$companyB, $branchB, $ingredientB, , $gramB] = $this->inventoryContext();

        $this->expectException(AuthorizationException::class);

        $this->open($companyB, $branchB, $ingredientB, $gramB, $ownerA, '10.000', '1.000000');
        $this->assertNotSame($companyA->getKey(), $companyB->getKey());
    }

    public function test_branch_must_belong_to_the_selected_company(): void
    {
        [$company, , $ingredient, $owner, $gram] = $this->inventoryContext();
        $otherBranch = Branch::factory()->create();

        $this->expectException(DomainException::class);

        $this->open($company, $otherBranch, $ingredient, $gram, $owner, '10.000', '1.000000');
    }

    public function test_inventory_item_must_belong_to_the_selected_company(): void
    {
        [$company, $branch, , $owner] = $this->inventoryContext();
        [, , $otherIngredient, , $otherGram] = $this->inventoryContext();

        $this->expectException(DomainException::class);

        $this->open($company, $branch, $otherIngredient, $otherGram, $owner, '10.000', '1.000000');
    }

    public function test_posted_purchase_cannot_be_posted_twice(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $purchase = $this->purchase($company, $branch, $ingredient, $gram, $owner);
        app(PostPurchaseAction::class)->execute($purchase, $owner);

        $this->expectException(DomainException::class);

        app(PostPurchaseAction::class)->execute($purchase->refresh(), $owner);
    }

    public function test_reversal_restores_opening_balance_and_cannot_run_twice(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $opening = $this->open($company, $branch, $ingredient, $gram, $owner, '100.000', '0.500000');

        app(ReverseInventoryMovementAction::class)->execute($opening, $owner, 'carga incorrecta');

        $stock = $this->stock($company, $branch, $ingredient);
        $this->assertSame('0.000', $stock->quantity);
        $this->assertSame('0.000000', $stock->average_cost);

        $this->expectException(DomainException::class);
        app(ReverseInventoryMovementAction::class)->execute($opening, $owner, 'segundo intento');
    }

    public function test_posted_purchase_can_be_reversed_without_deleting_history(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '100.000', '0.400000');
        $purchase = $this->purchase($company, $branch, $ingredient, $gram, $owner, '100.000', '0.500000');
        app(PostPurchaseAction::class)->execute($purchase, $owner);

        app(ReversePurchaseAction::class)->execute($purchase->refresh(), $owner, 'documento incorrecto');

        $this->assertSame(PurchaseStatus::Reversed, $purchase->refresh()->status);
        $stock = $this->stock($company, $branch, $ingredient);
        $this->assertSame('100.000', $stock->quantity);
        $this->assertSame('0.400000', $stock->average_cost);
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_posted_movement_is_immutable(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $movement = $this->open($company, $branch, $ingredient, $gram, $owner);

        $this->expectException(ImmutableInventoryMovementException::class);

        $movement->update(['reason' => 'edited']);
    }

    public function test_centralized_updates_accumulate_without_lost_values(): void
    {
        [$company, $branch, $ingredient, $owner, $gram] = $this->inventoryContext();
        $this->open($company, $branch, $ingredient, $gram, $owner, '100.000', '0.010000');
        $action = app(RegisterInventoryAdjustmentAction::class);

        $action->execute(
            $company,
            $branch,
            $ingredient,
            InventoryMovementType::AdjustmentIn,
            '20.000',
            $gram,
            'primer conteo',
            $owner,
        );
        $action->execute(
            $company,
            $branch,
            $ingredient,
            InventoryMovementType::AdjustmentIn,
            '30.000',
            $gram,
            'segundo conteo',
            $owner,
        );

        $this->assertSame('150.000', $this->stock($company, $branch, $ingredient)->quantity);
    }

    public function test_inventory_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstStocks = InventoryStock::query()
            ->orderBy('inventory_item_id')
            ->get(['inventory_item_id', 'quantity', 'average_cost'])
            ->toArray();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('inventory_stocks', 10);
        $this->assertDatabaseCount('inventory_movements', 10);
        $this->assertSame(
            $firstStocks,
            InventoryStock::query()
                ->orderBy('inventory_item_id')
                ->get(['inventory_item_id', 'quantity', 'average_cost'])
                ->toArray(),
        );
    }

    /** @return array{Company, Branch, InventoryItem, User, Unit} */
    private function inventoryContext(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $gram = $this->unit($company, 'Gramo', 'g', UnitType::Weight);
        $ingredient = Ingredient::factory()->for($gram)->create();
        $inventoryItem = InventoryItem::factory()->for($gram)->create([
            'company_id' => $company->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'name' => $ingredient->name,
        ]);

        return [$company, $branch, $inventoryItem, $owner, $gram];
    }

    private function unit(Company $company, string $name, string $symbol, UnitType $type): Unit
    {
        return Unit::factory()->for($company)->create(compact('name', 'symbol', 'type'));
    }

    private function open(
        Company $company,
        Branch $branch,
        InventoryItem $ingredient,
        Unit $unit,
        User $owner,
        string $quantity = '10.000',
        string $unitCost = '1.000000',
    ): InventoryMovement {
        return app(RegisterOpeningStockAction::class)->execute(
            $company,
            $branch,
            $ingredient,
            $quantity,
            $unit,
            $unitCost,
            $owner,
        );
    }

    private function purchase(
        Company $company,
        Branch $branch,
        InventoryItem $ingredient,
        Unit $inputUnit,
        User $owner,
        string $quantity = '10.000',
        string $unitCost = '1.000000',
    ): Purchase {
        $purchase = Purchase::factory()->for($branch)->create([
            'company_id' => $company->getKey(),
            'created_by' => $owner->getKey(),
            'document_number' => null,
        ]);

        PurchaseItem::query()->create([
            'company_id' => $company->getKey(),
            'purchase_id' => $purchase->getKey(),
            'inventory_item_id' => $ingredient->getKey(),
            'quantity' => $quantity,
            'input_unit_id' => $inputUnit->getKey(),
            'base_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => '0.000000',
        ]);

        return $purchase;
    }

    private function stock(Company $company, Branch $branch, InventoryItem $ingredient): InventoryStock
    {
        return InventoryStock::query()
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('inventory_item_id', $ingredient->getKey())
            ->firstOrFail();
    }
}
