<?php

namespace App\Actions;

use App\Enums\RecipeComponentType;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRecipeAction
{
    /**
     * @param  list<array{ingredient_id: int, component_type?: string, quantity: int|string}>  $items
     */
    public function execute(
        Company $company,
        ProductVariant $variant,
        array $items,
        ?string $name = null,
        bool $isActive = true,
    ): Recipe {
        if ((int) $variant->company_id !== (int) $company->getKey()) {
            throw new AuthorizationException('La variante no pertenece a la empresa activa.');
        }
        if (! $variant->requires_preparation) {
            throw ValidationException::withMessages([
                'variant' => 'Los productos de venta directa no requieren receta.',
            ]);
        }

        $validatedItems = $this->validateItems($company, $items);

        return DB::transaction(function () use ($company, $variant, $validatedItems, $name, $isActive): Recipe {
            $lockedVariant = ProductVariant::query()
                ->forCompany($company)
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($isActive
                && $lockedVariant->inventoryItem()->where('is_active', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'variant' => 'Una variante con stock directo no puede usar una receta activa.',
                ]);
            }

            $recipe = Recipe::query()
                ->where('product_variant_id', $variant->getKey())
                ->lockForUpdate()
                ->first();

            if ($recipe) {
                $recipe->update(['name' => $name, 'is_active' => $isActive]);
            } else {
                $recipe = Recipe::query()->create([
                    'company_id' => $company->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'name' => $name,
                    'is_active' => $isActive,
                ]);
            }

            $ingredientIds = array_keys($validatedItems);

            if ($ingredientIds === []) {
                $recipe->items()->delete();
            } else {
                $recipe->items()->whereNotIn('ingredient_id', $ingredientIds)->delete();
            }

            $now = now();
            $rows = array_map(
                fn (int $ingredientId, array $item): array => [
                    'company_id' => $company->getKey(),
                    'recipe_id' => $recipe->getKey(),
                    'ingredient_id' => $ingredientId,
                    'component_type' => $item['component_type'],
                    'quantity' => $item['quantity'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $ingredientIds,
                array_values($validatedItems),
            );

            if ($rows !== []) {
                RecipeItem::query()->upsert(
                    $rows,
                    ['recipe_id', 'ingredient_id'],
                    ['component_type', 'quantity', 'updated_at'],
                );
            }

            return $recipe->refresh()->load('items.ingredient.unit');
        });
    }

    /**
     * @param  list<array{ingredient_id: int, component_type?: string, quantity: int|string}>  $items
     * @return array<int, array{component_type:string,quantity:string}>
     */
    private function validateItems(Company $company, array $items): array
    {
        $validated = [];

        foreach ($items as $index => $item) {
            $ingredientId = (int) ($item['ingredient_id'] ?? 0);
            $quantity = $item['quantity'] ?? null;

            if ($ingredientId < 1) {
                throw ValidationException::withMessages([
                    "items.$index.ingredient_id" => 'Selecciona un ingrediente.',
                ]);
            }

            if (array_key_exists($ingredientId, $validated)) {
                throw ValidationException::withMessages([
                    "items.$index.ingredient_id" => 'Ese ingrediente ya está incluido en la receta.',
                ]);
            }

            try {
                $validQuantity = BigDecimal::of($quantity ?? '');
            } catch (NumberFormatException) {
                $validQuantity = null;
            }
            if ($validQuantity === null || $validQuantity->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'La cantidad debe ser mayor que cero.',
                ]);
            }

            $componentType = $item['component_type'] ?? RecipeComponentType::Topping->value;
            if (! RecipeComponentType::tryFrom($componentType)) {
                throw ValidationException::withMessages(["items.$index.component_type" => 'El componente de receta no es válido.']);
            }

            $validated[$ingredientId] = ['component_type' => $componentType, 'quantity' => (string) $quantity];
        }

        $validIngredientCount = Ingredient::query()
            ->forCompany($company)
            ->whereKey(array_keys($validated))
            ->count();

        if ($validIngredientCount !== count($validated)) {
            throw ValidationException::withMessages([
                'items' => 'Todos los ingredientes deben pertenecer a la empresa activa.',
            ]);
        }

        return $validated;
    }
}
