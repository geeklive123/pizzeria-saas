<?php

namespace App\Actions;

use App\Enums\ModifierOptionType;
use App\Enums\ProductModifierPurpose;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ModifierOption;
use App\Models\ProductModifier;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveToppingAction
{
    public function execute(Company $company, array $data, ?ModifierOption $topping = null): ModifierOption
    {
        return DB::transaction(function () use ($company, $data, $topping): ModifierOption {
            if ($topping?->exists && ((int) $topping->company_id !== (int) $company->getKey()
                || $topping->modifier?->purpose !== ProductModifierPurpose::ToppingCatalog)) {
                throw new DomainException('El topping no pertenece al catálogo de la empresa activa.');
            }

            $modifier = ProductModifier::query()->firstOrCreate(
                ['company_id' => $company->getKey(), 'purpose' => ProductModifierPurpose::ToppingCatalog->value],
                ['product_id' => null, 'name' => 'Toppings y extras', 'is_active' => true, 'sort_order' => 0],
            );

            $duplicate = ModifierOption::query()->forCompany($company)
                ->whereHas('modifier', fn ($query) => $query->where('purpose', ProductModifierPurpose::ToppingCatalog))
                ->where('name', $data['name'])
                ->when($topping?->exists, fn ($query) => $query->whereKeyNot($topping->getKey()))
                ->exists();
            if ($duplicate) {
                throw new DomainException('Ya existe un topping con ese nombre.');
            }

            $inventoryItem = filled($data['inventory_item_ulid'] ?? null)
                ? InventoryItem::query()->forCompany($company)
                    ->with(['ingredient', 'unit'])->where('ulid', $data['inventory_item_ulid'])->firstOrFail()
                : null;

            $topping ??= new ModifierOption;
            $topping->fill([
                'company_id' => $company->getKey(),
                'product_modifier_id' => $modifier->getKey(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => ModifierOptionType::Add,
                'price_delta' => $data['price_delta'],
                'inventory_item_id' => $inventoryItem?->getKey(),
                'ingredient_id' => $inventoryItem?->ingredient_id,
                'quantity' => $inventoryItem ? ($data['default_quantity'] ?? null) : null,
                'unit_id' => $inventoryItem?->unit_id,
                'is_active' => $data['is_active'],
                'sort_order' => $data['sort_order'],
            ])->save();

            $kept = [];
            foreach ($data['size_rules'] ?? [] as $sizeRule) {
                if (! filled($sizeRule['price_delta'] ?? null) && ! filled($sizeRule['quantity'] ?? null)) {
                    continue;
                }

                $rule = $topping->sizeRules()->updateOrCreate(
                    ['company_id' => $company->getKey(), 'size_key' => $sizeRule['size_key']],
                    [
                        'price_delta' => $sizeRule['price_delta'] ?? null,
                        'quantity' => $inventoryItem ? ($sizeRule['quantity'] ?? null) : null,
                    ],
                );
                $kept[] = $rule->getKey();
            }
            $topping->sizeRules()->when($kept, fn ($query) => $query->whereNotIn('id', $kept))->delete();

            return $topping->refresh()->load(['modifier', 'inventoryItem.unit', 'sizeRules']);
        });
    }
}
