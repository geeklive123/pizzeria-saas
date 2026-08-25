<?php

namespace Tests\Feature;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\OrderPosCatalogService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasaYManaMenuSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasaYManaMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_menu_import_is_complete_idempotent_and_does_not_invent_inventory_or_recipes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $beforeInventory = $this->inventoryCounts();

        $this->seed(MasaYManaMenuSeeder::class);
        $firstCounts = $this->menuCounts();
        $afterFirstInventory = $this->inventoryCounts();
        $this->seed(MasaYManaMenuSeeder::class);

        $this->assertSame($firstCounts, $this->menuCounts());
        $this->assertSame(['products' => 32, 'variants' => 66, 'categories' => 5], $firstCounts);
        $this->assertSame($beforeInventory['stocks'], $afterFirstInventory['stocks']);
        $this->assertSame($beforeInventory['movements'], $afterFirstInventory['movements']);
        $this->assertSame($afterFirstInventory, $this->inventoryCounts());
        $this->assertSame($beforeInventory['items'] + 13, $afterFirstInventory['items']);

        $company = $this->company();
        $realPizzaIds = Product::query()->where('company_id', $company->id)
            ->whereIn('category_id', Category::query()->where('company_id', $company->id)
                ->whereIn('name', ['Pizzas con maña', 'Las de siempre - con maña'])->select('id'))
            ->pluck('id');
        $realVariantIds = ProductVariant::query()->whereIn('product_id', $realPizzaIds)->pluck('id');
        $this->assertCount(17, $realPizzaIds);
        $this->assertCount(51, $realVariantIds);
        $this->assertSame(0, Recipe::query()->whereIn('product_variant_id', $realVariantIds)->count());
        $this->assertSame(0, RecipeItem::query()->whereIn('recipe_id', Recipe::query()
            ->whereIn('product_variant_id', $realVariantIds)->select('id'))->count());

        $this->assertDatabaseMissing('products', [
            'company_id' => $company->id,
            'name' => 'VINO DE ESPECIALIDAD(CONSULTAR LA ELECCION DE TEMPORADA)',
        ]);
        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'name' => 'Pizza Pepperoni',
            'is_active' => false,
        ]);
        $juiceCategory = Category::query()->where('company_id', $company->id)
            ->where('name', 'Jugos naturales')->firstOrFail();
        $this->assertSame(2, ProductVariant::query()->where('company_id', $company->id)
            ->whereHas('product', fn ($query) => $query->where('category_id', $juiceCategory->id))
            ->where('is_active', false)->count());
    }

    public function test_personal_is_single_flavor_while_mediana_and_familiar_allow_one_to_four_without_fictitious_inventory(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(MasaYManaMenuSeeder::class);
        $company = $this->company();
        $branch = Branch::query()->where('company_id', $company->id)
            ->where('name', 'Principal')->firstOrFail();
        $owner = Membership::query()->where('company_id', $company->id)
            ->where('is_active', true)->with('user')->firstOrFail()->user;
        $catalog = app(OrderPosCatalogService::class)->forOrderScreen($company, $branch);
        $movementCount = InventoryMovement::query()->count();
        $stockCount = InventoryStock::query()->count();

        foreach (['personal', 'mediana', 'familiar'] as $sizeKey) {
            $variants = $catalog['pizzaVariants']->get($sizeKey);
            $this->assertCount(17, $variants, "El tamaño {$sizeKey} debe ofrecer los 17 sabores reales.");
            $this->assertTrue($variants->every(fn (ProductVariant $variant): bool => $variant->size_key === $sizeKey));
            $this->assertTrue($variants->every(
                fn (ProductVariant $variant): bool => $variant->sellable_availability->mode === 'recipe_pending',
            ));

            $allowedCounts = $sizeKey === 'personal' ? [1] : [1, 2, 3, 4];
            foreach ($allowedCounts as $flavorCount) {
                $selected = $variants->take($flavorCount)->values();
                $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
                $item = app(AddConfiguredPizzaAction::class)->execute(
                    $order,
                    $selected->map(fn (ProductVariant $variant): array => ['product_variant' => $variant])->all(),
                    '1.000',
                    $owner,
                    OrderType::DineIn,
                );

                $this->assertCount($flavorCount, $item->sections);
                $this->assertSame('recipe_pending', $item->configuration_snapshot['inventory_status']);
                $this->assertSame([], $item->configuration_snapshot['requirements']);
                $this->assertSame(
                    $selected->max(fn (ProductVariant $variant): string => $variant->price),
                    $item->unit_price,
                );
                $this->assertSame(0, InventoryReservation::query()->where('order_item_id', $item->id)->count());
            }

            if ($sizeKey === 'personal') {
                try {
                    app(AddConfiguredPizzaAction::class)->execute(
                        app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner),
                        $variants->take(2)->map(fn (ProductVariant $variant): array => ['product_variant' => $variant])->all(),
                        '1.000',
                        $owner,
                        OrderType::DineIn,
                    );
                    $this->fail('Personal no debe aceptar dos sabores.');
                } catch (DomainException $exception) {
                    $this->assertStringContainsString('Personal no permite combinar', $exception->getMessage());
                }
            }
        }

        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame($stockCount, InventoryStock::query()->count());
    }

    /** @return array{products:int, variants:int, categories:int} */
    private function menuCounts(): array
    {
        $company = $this->company();
        $categoryIds = Category::query()->where('company_id', $company->id)->whereIn('name', [
            'Pizzas con maña',
            'Las de siempre - con maña',
            'Gaseosas',
            'Jugos naturales',
            'Bebidas alcohólicas',
        ])->pluck('id');
        $productIds = Product::query()->where('company_id', $company->id)
            ->whereIn('category_id', $categoryIds)->pluck('id');

        return [
            'products' => $productIds->count(),
            'variants' => ProductVariant::query()->whereIn('product_id', $productIds)->count(),
            'categories' => $categoryIds->count(),
        ];
    }

    /** @return array{items:int, stocks:int, movements:int} */
    private function inventoryCounts(): array
    {
        return [
            'items' => InventoryItem::query()->count(),
            'stocks' => InventoryStock::query()->count(),
            'movements' => InventoryMovement::query()->count(),
        ];
    }

    private function company(): Company
    {
        return Company::query()->where('name', 'Mi Pizzería')->firstOrFail();
    }
}
