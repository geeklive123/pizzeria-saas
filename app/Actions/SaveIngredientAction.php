<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveIngredientAction
{
    public function execute(Company $company, array $data, ?Ingredient $ingredient = null): Ingredient
    {
        return DB::transaction(function () use ($company, $data, $ingredient): Ingredient {
            $ingredient ??= new Ingredient;

            if ($ingredient->exists
                && (int) $ingredient->unit_id !== (int) $data['unit_id']
                && $ingredient->inventoryItem?->inventoryMovements()->exists()) {
                throw new DomainException(
                    'No se puede cambiar la unidad base porque el ingrediente ya tiene movimientos de inventario.',
                );
            }

            $ingredient->fill([
                'company_id' => $company->getKey(),
                'unit_id' => $data['unit_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ])->save();

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

            return $ingredient->refresh()->load('unit');
        });
    }
}
