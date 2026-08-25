<?php

namespace Tests\Feature;

use App\Actions\PostPurchaseAction;
use App\Actions\RegisterOpeningStockAction;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredient_can_have_an_inventory_item(): void
    {
        $company = Company::factory()->create();
        $gram = $this->unit($company, 'Gramo', 'g', UnitType::Weight);
        $ingredient = Ingredient::factory()->for($gram)->create();

        $item = InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $gram->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'name' => $ingredient->name,
            'is_active' => true,
        ]);

        $this->assertTrue($ingredient->inventoryItem->is($item));
        $this->assertTrue($item->ingredient->is($ingredient));
        $this->assertNull($item->product_variant_id);
    }

    public function test_product_variant_can_have_an_inventory_item(): void
    {
        [$company, , $item, , , $variant] = $this->directSaleContext();

        $this->assertSame($company->getKey(), $item->company_id);
        $this->assertTrue($variant->inventoryItem->is($item));
        $this->assertTrue($item->productVariant->is($variant));
        $this->assertNull($item->ingredient_id);
    }

    public function test_inventory_item_cannot_represent_ingredient_and_variant_simultaneously(): void
    {
        [$company, , , , $unit, $variant] = $this->directSaleContext();
        $ingredient = Ingredient::factory()->for($unit)->create();

        $this->assertDomainException(fn () => InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $unit->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'product_variant_id' => $variant->getKey(),
            'name' => 'Invalid item',
            'is_active' => true,
        ]));

        $this->assertDatabaseCount('inventory_items', 1);
    }

    public function test_inventory_item_cannot_exist_without_a_source(): void
    {
        $company = Company::factory()->create();
        $unit = $this->unit($company, 'Unidad', 'u', UnitType::Unit);

        $this->assertDomainException(fn () => InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $unit->getKey(),
            'name' => 'Invalid item',
            'is_active' => true,
        ]));

        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_inventory_item_migration_can_resume_when_the_complete_table_already_exists(): void
    {
        $migration = require database_path('migrations/2026_08_20_120050_create_inventory_items_table.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumns('inventory_items', [
            'id',
            'ulid',
            'company_id',
            'unit_id',
            'ingredient_id',
            'product_variant_id',
            'name',
            'is_active',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_ingredient_inventory_item_must_use_its_base_unit(): void
    {
        $company = Company::factory()->create();
        $gram = $this->unit($company, 'Gramo', 'g', UnitType::Weight);
        $kilogram = $this->unit($company, 'Kilogramo', 'kg', UnitType::Weight);
        $ingredient = Ingredient::factory()->for($gram)->create();

        $this->expectException(DomainException::class);

        InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $kilogram->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'name' => $ingredient->name,
            'is_active' => true,
        ]);
    }

    public function test_direct_sale_product_can_own_inventory_stock(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->directSaleContext();
        $this->open($company, $branch, $item, $unit, $owner, '5.000', '6.500000');

        $stock = $item->inventoryStocks()->firstOrFail();

        $this->assertSame('5.000', $stock->quantity);
        $this->assertTrue($stock->inventoryItem->is($item));
    }

    public function test_coca_cola_opening_increases_stock(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->directSaleContext();

        $this->open($company, $branch, $item, $unit, $owner, '24.000', '6.500000');

        $stock = $this->stock($company, $branch, $item);
        $this->assertSame('24.000', $stock->quantity);
        $this->assertSame('6.500000', $stock->average_cost);
    }

    public function test_coca_cola_purchase_increases_stock(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->directSaleContext();
        $purchase = $this->purchase($company, $branch, $item, $unit, $owner, '24.000', '6.500000');

        app(PostPurchaseAction::class)->execute($purchase, $owner);

        $stock = $this->stock($company, $branch, $item);
        $this->assertSame('24.000', $stock->quantity);
        $this->assertSame('6.500000', $stock->average_cost);
    }

    public function test_weighted_average_cost_works_for_counted_units(): void
    {
        [$company, $branch, $item, $owner, $unit] = $this->directSaleContext();
        $this->open($company, $branch, $item, $unit, $owner, '24.000', '6.500000');
        $purchase = $this->purchase($company, $branch, $item, $unit, $owner, '24.000', '7.500000');

        app(PostPurchaseAction::class)->execute($purchase, $owner);

        $stock = $this->stock($company, $branch, $item);
        $this->assertSame('48.000', $stock->quantity);
        $this->assertSame('7.000000', $stock->average_cost);
    }

    public function test_inventory_item_is_isolated_by_company(): void
    {
        [, , , $ownerA] = $this->directSaleContext();
        [$companyB, $branchB, $itemB, , $unitB] = $this->directSaleContext();

        $this->expectException(AuthorizationException::class);

        $this->open($companyB, $branchB, $itemB, $unitB, $ownerA, '1.000', '1.000000');
    }

    public function test_product_variant_from_another_company_cannot_be_associated(): void
    {
        $companyA = Company::factory()->create();
        $unitA = $this->unit($companyA, 'Unidad A', 'u', UnitType::Unit);
        [, , , , , $variantB] = $this->directSaleContext();

        $this->expectException(DomainException::class);

        InventoryItem::query()->create([
            'company_id' => $companyA->getKey(),
            'unit_id' => $unitA->getKey(),
            'product_variant_id' => $variantB->getKey(),
            'name' => 'Foreign product',
            'is_active' => true,
        ]);
    }

    public function test_inventory_item_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstStocks = InventoryStock::query()
            ->orderBy('inventory_item_id')
            ->get(['inventory_item_id', 'quantity', 'average_cost'])
            ->toArray();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('inventory_items', 11);
        $this->assertDatabaseCount('inventory_stocks', 10);
        $this->assertDatabaseCount('inventory_movements', 10);
        $this->assertSame(
            0,
            ProductVariant::query()
                ->whereHas('product', fn ($product) => $product->where('type', ProductType::Pizza))
                ->whereHas('inventoryItem')
                ->count(),
        );
        $this->assertSame(
            $firstStocks,
            InventoryStock::query()
                ->orderBy('inventory_item_id')
                ->get(['inventory_item_id', 'quantity', 'average_cost'])
                ->toArray(),
        );
        $this->assertDatabaseHas('inventory_stocks', [
            'inventory_item_id' => InventoryItem::query()
                ->where('name', 'Coca-Cola 500 ml')
                ->value('id'),
            'quantity' => 24,
            'average_cost' => 6.5,
        ]);
    }

    /** @return array{Company, Branch, InventoryItem, User, Unit, ProductVariant} */
    private function directSaleContext(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = $this->unit($company, 'Unidad', 'u', UnitType::Unit);
        $product = Product::factory()->for($company)->create([
            'name' => fake()->unique()->words(3, true),
            'type' => ProductType::Beverage,
        ]);
        $variant = ProductVariant::factory()->for($product)->create(['name' => '500 ml']);
        $item = InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $unit->getKey(),
            'product_variant_id' => $variant->getKey(),
            'name' => "{$product->name} {$variant->name}",
            'is_active' => true,
        ]);

        return [$company, $branch, $item, $owner, $unit, $variant];
    }

    private function unit(Company $company, string $name, string $symbol, UnitType $type): Unit
    {
        return Unit::factory()->for($company)->create(compact('name', 'symbol', 'type'));
    }

    private function open(
        Company $company,
        Branch $branch,
        InventoryItem $item,
        Unit $unit,
        User $owner,
        string $quantity,
        string $unitCost,
    ): void {
        app(RegisterOpeningStockAction::class)->execute(
            $company,
            $branch,
            $item,
            $quantity,
            $unit,
            $unitCost,
            $owner,
        );
    }

    private function purchase(
        Company $company,
        Branch $branch,
        InventoryItem $item,
        Unit $inputUnit,
        User $owner,
        string $quantity,
        string $unitCost,
    ): Purchase {
        $purchase = Purchase::factory()->for($branch)->create([
            'company_id' => $company->getKey(),
            'created_by' => $owner->getKey(),
            'document_number' => null,
        ]);

        PurchaseItem::query()->create([
            'company_id' => $company->getKey(),
            'purchase_id' => $purchase->getKey(),
            'inventory_item_id' => $item->getKey(),
            'quantity' => $quantity,
            'input_unit_id' => $inputUnit->getKey(),
            'base_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => '0.000000',
        ]);

        return $purchase;
    }

    private function stock(Company $company, Branch $branch, InventoryItem $item): InventoryStock
    {
        return InventoryStock::query()
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('inventory_item_id', $item->getKey())
            ->firstOrFail();
    }

    private function assertDomainException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a domain exception for an invalid inventory item source.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
