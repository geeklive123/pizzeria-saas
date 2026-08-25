<?php

namespace Database\Seeders;

use App\Actions\RegisterOpeningStockAction;
use App\Enums\InventoryMovementType;
use App\Enums\MembershipRole;
use App\Enums\ModifierOptionType;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\ModifierOption;
use App\Models\PackagingRule;
use App\Models\ProductModifier;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(RegisterOpeningStockAction $registerOpening): void
    {
        $company = Company::query()->where('name', 'Mi Pizzería')->firstOrFail();
        $branch = Branch::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Principal')
            ->firstOrFail();
        $owner = Membership::query()
            ->where('company_id', $company->getKey())
            ->where('role', MembershipRole::Owner)
            ->where('is_active', true)
            ->with('user')
            ->firstOrFail()
            ->user;

        Ingredient::query()
            ->where('company_id', $company->getKey())
            ->with('unit')
            ->get()
            ->each(function (Ingredient $ingredient) use ($company): void {
                InventoryItem::query()->updateOrCreate(
                    ['ingredient_id' => $ingredient->getKey()],
                    [
                        'company_id' => $company->getKey(),
                        'unit_id' => $ingredient->unit_id,
                        'product_variant_id' => null,
                        'name' => $ingredient->name,
                        'is_active' => $ingredient->is_active,
                    ],
                );
            });

        $unit = Unit::query()
            ->where('company_id', $company->getKey())
            ->where('symbol', 'u')
            ->firstOrFail();
        $cocaColaVariant = ProductVariant::query()
            ->where('company_id', $company->getKey())
            ->where('name', '500 ml')
            ->whereHas('product', fn ($product) => $product->where('name', 'Coca-Cola 500 ml'))
            ->firstOrFail();

        InventoryItem::query()->updateOrCreate(
            ['product_variant_id' => $cocaColaVariant->getKey()],
            [
                'company_id' => $company->getKey(),
                'unit_id' => $unit->getKey(),
                'ingredient_id' => null,
                'name' => 'Coca-Cola 500 ml',
                'is_active' => true,
            ],
        );

        $openingStocks = [
            'Harina' => ['quantity' => '20000.000', 'unit_cost' => '0.006000'],
            'Mozzarella' => ['quantity' => '10000.000', 'unit_cost' => '0.045000'],
            'Salsa de tomate' => ['quantity' => '8000.000', 'unit_cost' => '0.012000'],
            'Pepperoni' => ['quantity' => '5000.000', 'unit_cost' => '0.050000'],
            'Jamón' => ['quantity' => '4000.000', 'unit_cost' => '0.035000'],
            'Piña' => ['quantity' => '3000.000', 'unit_cost' => '0.020000'],
            'Caja personal' => ['quantity' => '50.000', 'unit_cost' => '2.000000'],
            'Caja mediana' => ['quantity' => '50.000', 'unit_cost' => '3.000000'],
            'Caja familiar' => ['quantity' => '40.000', 'unit_cost' => '4.000000'],
            'Coca-Cola 500 ml' => ['quantity' => '24.000', 'unit_cost' => '6.500000'],
        ];

        foreach ($openingStocks as $inventoryItemName => $data) {
            $inventoryItem = InventoryItem::query()
                ->where('company_id', $company->getKey())
                ->where('name', $inventoryItemName)
                ->with('unit')
                ->firstOrFail();

            $openingExists = InventoryMovement::query()
                ->where('company_id', $company->getKey())
                ->where('branch_id', $branch->getKey())
                ->where('inventory_item_id', $inventoryItem->getKey())
                ->where('type', InventoryMovementType::Opening)
                ->exists();

            if ($openingExists) {
                continue;
            }

            $registerOpening->execute(
                $company,
                $branch,
                $inventoryItem,
                $data['quantity'],
                $inventoryItem->unit,
                $data['unit_cost'],
                $owner,
                referenceType: self::class,
                referenceId: $inventoryItem->getKey(),
                metadata: ['source' => 'demo_inventory_seed'],
            );
        }

        foreach (['personal' => 'Caja personal', 'mediana' => 'Caja mediana', 'familiar' => 'Caja familiar'] as $sizeKey => $itemName) {
            $packagingItem = InventoryItem::query()->where('company_id', $company->id)->where('name', $itemName)->firstOrFail();
            PackagingRule::query()->updateOrCreate([
                'company_id' => $company->id,
                'size_key' => $sizeKey,
                'fulfillment_type' => OrderType::Takeaway,
                'inventory_item_id' => $packagingItem->id,
            ], ['quantity' => '1.000']);
        }

        $modifier = ProductModifier::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Personalizaciones'],
            ['product_id' => null, 'is_active' => true, 'sort_order' => 0],
        );
        $mozzarella = InventoryItem::query()->where('company_id', $company->id)->where('name', 'Mozzarella')->with('ingredient')->firstOrFail();
        ModifierOption::query()->updateOrCreate(
            ['company_id' => $company->id, 'product_modifier_id' => $modifier->id, 'name' => 'Extra queso'],
            [
                'type' => ModifierOptionType::Add,
                'price_delta' => '5.00',
                'inventory_item_id' => $mozzarella->id,
                'ingredient_id' => $mozzarella->ingredient_id,
                'quantity' => '80.000',
                'unit_id' => $mozzarella->unit_id,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
        foreach (['Pepperoni', 'Piña'] as $sortOrder => $ingredientName) {
            $ingredientItem = InventoryItem::query()->where('company_id', $company->id)->where('name', $ingredientName)->firstOrFail();
            ModifierOption::query()->updateOrCreate(
                ['company_id' => $company->id, 'product_modifier_id' => $modifier->id, 'name' => "Sin {$ingredientName}"],
                [
                    'type' => ModifierOptionType::Remove,
                    'price_delta' => '0.00',
                    'inventory_item_id' => $ingredientItem->id,
                    'ingredient_id' => $ingredientItem->ingredient_id,
                    'quantity' => null,
                    'unit_id' => $ingredientItem->unit_id,
                    'is_active' => true,
                    'sort_order' => $sortOrder + 1,
                ],
            );
        }
    }
}
