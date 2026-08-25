<?php

namespace App\Models;

use App\Enums\RecipeComponentType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RecipeItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'recipe_id', 'ingredient_id', 'component_type', 'quantity'])]
class RecipeItem extends Model
{
    /** @use HasFactory<RecipeItemFactory> */
    use BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (RecipeItem $item): void {
            if (! is_numeric($item->quantity) || $item->quantity <= 0) {
                throw new DomainException('Recipe quantities must be greater than zero.');
            }

            $recipeIsValid = Recipe::query()
                ->whereKey($item->recipe_id)
                ->where('company_id', $item->company_id)
                ->exists();
            $ingredientIsValid = Ingredient::query()
                ->whereKey($item->ingredient_id)
                ->where('company_id', $item->company_id)
                ->exists();

            if (! $recipeIsValid || ! $ingredientIsValid) {
                throw new DomainException('The recipe and ingredient must belong to the same company.');
            }
        });
    }

    protected function casts(): array
    {
        return ['component_type' => RecipeComponentType::class, 'quantity' => 'decimal:3'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
