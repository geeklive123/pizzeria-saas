<?php

namespace Database\Seeders;

use App\Actions\InitializeStandardUnitsAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(UpdateRecipeAction $updateRecipe): void
    {
        $company = Company::query()->where('name', 'Mi Pizzería')->firstOrFail();

        DB::transaction(function () use ($company, $updateRecipe): void {
            $units = collect(InitializeStandardUnitsAction::definitions())
                ->mapWithKeys(function (array $data) use ($company): array {
                    $unit = Unit::query()->updateOrCreate(
                        ['company_id' => $company->getKey(), 'symbol' => $data['symbol']],
                        ['name' => $data['name'], 'type' => $data['type'], 'is_active' => true],
                    );

                    return [$data['symbol'] => $unit];
                });

            $categories = collect(['Pizzas', 'Bebidas', 'Extras', 'Combos'])
                ->mapWithKeys(function (string $name, int $sortOrder) use ($company): array {
                    $category = Category::query()->updateOrCreate(
                        ['company_id' => $company->getKey(), 'name' => $name],
                        ['is_active' => true, 'sort_order' => $sortOrder],
                    );

                    return [$name => $category];
                });

            $ingredientUnits = [
                'Harina' => 'g',
                'Masa' => 'g',
                'Salsa de tomate' => 'g',
                'Mozzarella' => 'g',
                'Pepperoni' => 'g',
                'Jamón' => 'g',
                'Piña' => 'g',
                'Caja personal' => 'u',
                'Caja mediana' => 'u',
                'Caja familiar' => 'u',
            ];

            $ingredients = collect($ingredientUnits)->mapWithKeys(
                function (string $unitSymbol, string $name) use ($company, $units): array {
                    $ingredient = Ingredient::query()->updateOrCreate(
                        ['company_id' => $company->getKey(), 'name' => $name],
                        [
                            'unit_id' => $units->get($unitSymbol)->getKey(),
                            'is_active' => true,
                        ],
                    );

                    return [$name => $ingredient];
                },
            );

            $pepperoni = Product::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Pizza Pepperoni'],
                [
                    'category_id' => $categories->get('Pizzas')->getKey(),
                    'type' => ProductType::Pizza,
                    'is_active' => true,
                ],
            );

            $variantData = [
                'Personal' => ['price' => '25.00', 'sort_order' => 0],
                'Mediana' => ['price' => '45.00', 'sort_order' => 1],
                'Familiar' => ['price' => '70.00', 'sort_order' => 2],
            ];

            $variants = collect($variantData)->mapWithKeys(
                function (array $data, string $name) use ($company, $pepperoni): array {
                    $variant = ProductVariant::query()->updateOrCreate(
                        ['product_id' => $pepperoni->getKey(), 'name' => $name],
                        [
                            'company_id' => $company->getKey(),
                            'size_key' => Str::slug($name),
                            'price' => $data['price'],
                            'requires_preparation' => true,
                            'is_active' => true,
                            'sort_order' => $data['sort_order'],
                        ],
                    );

                    return [$name => $variant];
                },
            );

            $recipes = [
                'Personal' => [
                    'Masa' => ['quantity' => '200.000', 'component_type' => 'base'],
                    'Salsa de tomate' => ['quantity' => '70.000', 'component_type' => 'base'],
                    'Mozzarella' => ['quantity' => '120.000', 'component_type' => 'base'],
                    'Pepperoni' => ['quantity' => '50.000', 'component_type' => 'topping'],
                ],
                'Mediana' => [
                    'Masa' => ['quantity' => '320.000', 'component_type' => 'base'],
                    'Salsa de tomate' => ['quantity' => '110.000', 'component_type' => 'base'],
                    'Mozzarella' => ['quantity' => '200.000', 'component_type' => 'base'],
                    'Pepperoni' => ['quantity' => '80.000', 'component_type' => 'topping'],
                ],
                'Familiar' => [
                    'Masa' => ['quantity' => '450.000', 'component_type' => 'base'],
                    'Salsa de tomate' => ['quantity' => '150.000', 'component_type' => 'base'],
                    'Mozzarella' => ['quantity' => '300.000', 'component_type' => 'base'],
                    'Pepperoni' => ['quantity' => '120.000', 'component_type' => 'topping'],
                ],
            ];

            foreach ($recipes as $variantName => $recipeItems) {
                $items = collect($recipeItems)
                    ->map(fn (array $item, string $ingredientName): array => [
                        'ingredient_id' => $ingredients->get($ingredientName)->getKey(),
                        'component_type' => $item['component_type'],
                        'quantity' => $item['quantity'],
                    ])
                    ->values()
                    ->all();

                $updateRecipe->execute(
                    $company,
                    $variants->get($variantName),
                    $items,
                    "Receta Pizza Pepperoni $variantName",
                );
            }

            $cocaCola = Product::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Coca-Cola 500 ml'],
                [
                    'category_id' => $categories->get('Bebidas')->getKey(),
                    'type' => ProductType::Beverage,
                    'is_active' => true,
                ],
            );

            ProductVariant::query()->updateOrCreate(
                ['product_id' => $cocaCola->getKey(), 'name' => '500 ml'],
                [
                    'company_id' => $company->getKey(),
                    'sku' => 'COCA-500',
                    'price' => '10.00',
                    'requires_preparation' => false,
                    'is_active' => true,
                    'sort_order' => 0,
                ],
            );
        });
    }
}
