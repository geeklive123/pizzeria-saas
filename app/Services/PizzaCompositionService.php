<?php

namespace App\Services;

use App\Enums\ModifierOptionType;
use App\Enums\OrderType;
use App\Enums\ProductModifierPurpose;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ModifierOption;
use App\Models\PackagingRule;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DomainException;

class PizzaCompositionService
{
    public function __construct(private readonly VariantSizeKeyService $sizeKeys) {}

    /**
     * Fractions are kept as BigRational values and aggregated before the final
     * reservation rounding to 3 decimals using HalfUp.
     *
     * @param  list<array<string, mixed>>  $sectionData
     * @param  list<array<string, mixed>>  $modifierData
     * @param  list<mixed>  $toppingData
     * @return array{unit_price:string, primary_variant:ProductVariant, sections:list<array<string,mixed>>, modifiers:list<array<string,mixed>>, requirements:list<array{inventory_item:InventoryItem,quantity:string}>, snapshot:array<string,mixed>}
     */
    public function compose(Company $company, array $sectionData, array $modifierData, OrderType $fulfillment, array $toppingData = []): array
    {
        if ($sectionData === [] || count($sectionData) > 4) {
            throw new DomainException('Una pizza debe tener entre 1 y 4 sabores.');
        }

        $sections = [];
        $sectionCount = count($sectionData);
        $selectedVariantIds = [];
        foreach (array_values($sectionData) as $index => $data) {
            $variant = $this->resolveVariant($company, $data);
            if (isset($selectedVariantIds[$variant->getKey()])) {
                throw new DomainException('Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción "1 sabor".');
            }
            $selectedVariantIds[$variant->getKey()] = true;

            $numerator = 1;
            $denominator = $sectionCount;
            $fraction = BigRational::ofFraction($numerator, $denominator);
            $sections[] = [
                'position' => $index + 1,
                'variant' => $variant,
                'fraction' => $fraction,
                'fraction_numerator' => $numerator,
                'fraction_denominator' => $denominator,
            ];
        }

        $sizeKey = $this->sizeKeys->fromVariant($sections[0]['variant']);
        if (blank($sizeKey) || collect($sections)->contains(
            fn (array $section): bool => $this->sizeKeys->fromVariant($section['variant']) !== $sizeKey,
        )) {
            throw new DomainException('Todos los sabores deben usar variantes del mismo tamaño compatible.');
        }
        if ($sectionCount > 1 && ! in_array($sizeKey, ['mediana', 'familiar'], true)) {
            throw new DomainException('La pizza Personal no permite combinar sabores. Solo Mediana y Grande admiten de 2 a 4 sabores.');
        }

        $configuredRecipes = collect($sections)->map(
            fn (array $section): bool => $section['variant']->recipe?->is_active === true
                && $section['variant']->recipe->items->isNotEmpty(),
        );
        if ($configuredRecipes->contains(true) && $configuredRecipes->contains(false)) {
            throw new DomainException('No se pueden mezclar sabores con receta configurada y sabores con receta pendiente.');
        }
        $recipePending = ! $configuredRecipes->contains(true);
        if ($recipePending && $modifierData !== []) {
            throw new DomainException('No se pueden aplicar extras o removidos hasta configurar las cantidades de receta.');
        }
        $pricingSection = $this->highestPricedSection($sections);

        /** @var array<int, BigRational> $recipeTotals */
        $recipeTotals = [];
        /** @var array<int, array<int, BigRational>> $recipeBySection */
        $recipeBySection = [];
        $inventoryItems = [];
        $pricingRecipe = $pricingSection['variant']->recipe;
        $structuralBaseItems = $pricingRecipe?->items->filter(fn ($item): bool => $this->isStructuralBase($item)) ?? collect();

        foreach ($structuralBaseItems as $recipeItem) {
            $inventoryItem = $this->inventoryItemForRecipe($recipeItem);
            $inventoryItems[$inventoryItem->id] = $inventoryItem;
            $quantity = BigRational::of($recipeItem->quantity);
            $recipeTotals[$inventoryItem->id] = ($recipeTotals[$inventoryItem->id] ?? BigRational::zero())->plus($quantity);
            foreach ($sections as $section) {
                $recipeBySection[$section['position']][$inventoryItem->id] = ($recipeBySection[$section['position']][$inventoryItem->id] ?? BigRational::zero())
                    ->plus($quantity->multipliedBy($section['fraction']));
            }
        }

        foreach ($recipePending ? [] : $sections as $section) {
            $recipeItems = $section['variant']->recipe->items
                ->reject(fn ($item): bool => $this->isStructuralBase($item));

            foreach ($recipeItems as $recipeItem) {
                $inventoryItem = $this->inventoryItemForRecipe($recipeItem);
                $inventoryItems[$inventoryItem->id] = $inventoryItem;
                $quantity = BigRational::of($recipeItem->quantity)->multipliedBy($section['fraction']);
                $recipeTotals[$inventoryItem->id] = ($recipeTotals[$inventoryItem->id] ?? BigRational::zero())->plus($quantity);
                $recipeBySection[$section['position']][$inventoryItem->id] = ($recipeBySection[$section['position']][$inventoryItem->id] ?? BigRational::zero())->plus($quantity);
            }
        }

        $totals = $recipeTotals;
        $price = BigDecimal::of($pricingSection['variant']->price);
        $modifierSnapshots = [];
        $removedScopes = [];

        foreach ($modifierData as $data) {
            $option = $this->resolveOption($company, $data);
            $sectionPosition = filled($data['section_position'] ?? null) ? (int) $data['section_position'] : null;
            $targetSection = $sectionPosition === null ? null : collect($sections)->firstWhere('position', $sectionPosition);
            if ($sectionPosition !== null && ! $targetSection) {
                throw new DomainException('La sección elegida para el modificador no existe.');
            }
            if ($option->modifier->product_id && ! collect($sections)->contains(fn (array $section): bool => (int) $section['variant']->product_id === (int) $option->modifier->product_id)) {
                throw new DomainException('El modificador no es compatible con los sabores elegidos.');
            }
            if ($targetSection && $option->modifier->product_id && (int) $targetSection['variant']->product_id !== (int) $option->modifier->product_id) {
                throw new DomainException('El modificador no es compatible con la sección elegida.');
            }

            $scale = $targetSection['fraction'] ?? BigRational::one();
            $inventoryItem = $option->inventoryItem ?? $option->ingredient?->inventoryItem;
            if (! $inventoryItem || ! $inventoryItem->is_active) {
                throw new DomainException("El modificador {$option->name} no tiene un artículo de inventario activo.");
            }
            $inventoryItems[$inventoryItem->id] = $inventoryItem;

            if ($option->type === ModifierOptionType::Add) {
                $quantity = BigRational::of($option->quantity)->multipliedBy($scale);
                $totals[$inventoryItem->id] = ($totals[$inventoryItem->id] ?? BigRational::zero())->plus($quantity);
                $price = $price->plus($this->decimal($scale->multipliedBy($option->price_delta), 2));
            } else {
                $scopeKey = ($sectionPosition ?? 'all').':'.$inventoryItem->id;
                if (isset($removedScopes[$scopeKey])) {
                    throw new DomainException("El ingrediente {$inventoryItem->name} ya fue removido en ese alcance.");
                }
                $removedScopes[$scopeKey] = true;
                $quantity = $sectionPosition === null
                    ? ($recipeTotals[$inventoryItem->id] ?? BigRational::zero())
                    : ($recipeBySection[$sectionPosition][$inventoryItem->id] ?? BigRational::zero());
                if ($quantity->isZero()) {
                    throw new DomainException("{$inventoryItem->name} no forma parte de la sección elegida.");
                }
                $totals[$inventoryItem->id] = ($totals[$inventoryItem->id] ?? BigRational::zero())->minus($quantity);
                if ($totals[$inventoryItem->id]->isNegative()) {
                    throw new DomainException('Una remoción no puede producir consumo negativo.');
                }
            }

            $modifierSnapshots[] = [
                'option' => $option,
                'purpose' => ProductModifierPurpose::OrderModifier,
                'section_position' => $sectionPosition,
                'type' => $option->type,
                'name_snapshot' => $option->name,
                'price_delta_snapshot' => (string) $this->decimal($scale->multipliedBy($option->price_delta), 2),
                'inventory_item' => $inventoryItem,
                'quantity_snapshot' => (string) $this->decimal($quantity, 3),
                'unit_id' => $inventoryItem->unit_id,
                'size_key_snapshot' => null,
                'price_source' => 'general',
            ];
        }

        $selectedToppings = [];
        foreach ($toppingData as $data) {
            $option = $this->resolveTopping($company, $data);
            if (isset($selectedToppings[$option->getKey()])) {
                throw new DomainException('El topping '.$option->name.' está seleccionado más de una vez.');
            }
            $selectedToppings[$option->getKey()] = true;

            $sizeRule = $option->sizeRules->firstWhere('size_key', $sizeKey);
            $usesSizePrice = $sizeRule?->price_delta !== null;
            $appliedPrice = BigDecimal::of($usesSizePrice ? $sizeRule->price_delta : ($option->price_delta ?? '0'));
            $price = $price->plus($appliedPrice);

            $inventoryItem = $option->inventoryItem;
            $quantity = null;
            if ($inventoryItem) {
                if (! $inventoryItem->is_active) {
                    throw new DomainException('El topping '.$option->name.' no tiene un artículo de inventario activo.');
                }

                $configuredQuantity = $sizeRule?->quantity ?? $option->quantity;
                if ($configuredQuantity === null) {
                    throw new DomainException('El topping '.$option->name.' no tiene una cantidad configurada para este tamaño.');
                }

                $quantity = BigRational::of($configuredQuantity);
                $inventoryItems[$inventoryItem->id] = $inventoryItem;
                $totals[$inventoryItem->id] = ($totals[$inventoryItem->id] ?? BigRational::zero())->plus($quantity);
            }

            $modifierSnapshots[] = [
                'option' => $option,
                'purpose' => ProductModifierPurpose::ToppingCatalog,
                'section_position' => null,
                'type' => ModifierOptionType::Add,
                'name_snapshot' => $option->name,
                'price_delta_snapshot' => (string) $appliedPrice->toScale(2, RoundingMode::HalfUp),
                'inventory_item' => $inventoryItem,
                'quantity_snapshot' => $quantity ? (string) $this->decimal($quantity, 3) : null,
                'unit_id' => $inventoryItem?->unit_id,
                'size_key_snapshot' => $sizeKey,
                'price_source' => $usesSizePrice ? 'size_rule' : 'general',
            ];
        }

        $packaging = [];
        foreach (PackagingRule::query()->forCompany($company)->where('size_key', $sizeKey)
            ->where('fulfillment_type', $fulfillment->value)->with('inventoryItem')->orderBy('inventory_item_id')->get() as $rule) {
            if (! $rule->inventoryItem->is_active) {
                throw new DomainException("El empaque {$rule->inventoryItem->name} no está activo.");
            }
            $inventoryItems[$rule->inventory_item_id] = $rule->inventoryItem;
            $quantity = BigRational::of($rule->quantity);
            $totals[$rule->inventory_item_id] = ($totals[$rule->inventory_item_id] ?? BigRational::zero())->plus($quantity);
            $packaging[] = ['inventory_item_id' => $rule->inventory_item_id, 'name' => $rule->inventoryItem->name, 'quantity' => (string) $rule->quantity];
        }

        $requirements = collect($totals)->reject(fn (BigRational $quantity): bool => $quantity->isZero())
            ->map(fn (BigRational $quantity, int $id): array => [
                'inventory_item' => $inventoryItems[$id],
                'quantity' => (string) $this->decimal($quantity, 3),
            ])->sortBy(fn (array $requirement) => $requirement['inventory_item']->id)->values()->all();

        $sectionSnapshots = array_map(fn (array $section): array => [
            ...$section,
            'unit_price_snapshot' => (string) $section['variant']->price,
            'product_name_snapshot' => $section['variant']->product->name,
            'variant_name_snapshot' => $section['variant']->name,
        ], $sections);
        $primary = $pricingSection['variant'];

        return [
            'unit_price' => (string) $price->toScale(2, RoundingMode::HalfUp),
            'primary_variant' => $primary,
            'sections' => $sectionSnapshots,
            'modifiers' => $modifierSnapshots,
            'requirements' => $requirements,
            'snapshot' => [
                'version' => 1,
                'size_key' => $sizeKey,
                'fulfillment_type' => $fulfillment->value,
                'pricing_policy' => 'highest_flavor_plus_additions',
                'rounding' => 'aggregate_rational_then_half_up_3_decimals',
                'inventory_status' => $recipePending ? 'recipe_pending' : 'configured',
                'inventory_notice' => $recipePending
                    ? 'Receta pendiente de cantidades: esta pizza no reserva ni descuenta ingredientes de receta.'
                    : null,
                'sections' => array_map(fn (array $section): array => [
                    'position' => $section['position'],
                    'fraction' => $section['fraction_numerator'].'/'.$section['fraction_denominator'],
                    'product' => $section['product_name_snapshot'],
                    'variant' => $section['variant_name_snapshot'],
                    'unit_price' => $section['unit_price_snapshot'],
                ], $sectionSnapshots),
                'modifiers' => array_map(fn (array $modifier): array => [
                    'purpose' => $modifier['purpose']->value,
                    'section_position' => $modifier['section_position'],
                    'type' => $modifier['type']->value,
                    'name' => $modifier['name_snapshot'],
                    'price_delta' => $modifier['price_delta_snapshot'],
                    'quantity' => $modifier['quantity_snapshot'],
                    'inventory_item_id' => $modifier['inventory_item']?->id,
                    'inventory_item' => $modifier['inventory_item']?->name,
                    'size_key' => $modifier['size_key_snapshot'],
                    'price_source' => $modifier['price_source'],
                ], $modifierSnapshots),
                'packaging' => $packaging,
                'requirements' => array_map(fn (array $requirement): array => [
                    'inventory_item_id' => $requirement['inventory_item']->id,
                    'name' => $requirement['inventory_item']->name,
                    'quantity' => $requirement['quantity'],
                ], $requirements),
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function resolveVariant(Company $company, array $data): ProductVariant
    {
        $candidate = $data['product_variant'] ?? $data['variant'] ?? $data['variant_id'] ?? null;
        $query = ProductVariant::query()->forCompany($company)->where('is_active', true)
            ->with(['product', 'recipe.items.ingredient.inventoryItem']);
        $variant = $candidate instanceof ProductVariant
            ? $query->find($candidate->id)
            : (is_numeric($candidate) ? $query->find($candidate) : $query->where('ulid', $candidate)->first());

        if (! $variant || $variant->product->type !== ProductType::Pizza) {
            throw new DomainException('Cada sabor debe ser una pizza activa.');
        }

        return $variant;
    }

    /** @param array<string, mixed> $data */
    private function resolveOption(Company $company, array $data): ModifierOption
    {
        $candidate = $data['option'] ?? $data['modifier_option'] ?? $data['option_id'] ?? null;
        $query = ModifierOption::query()->forCompany($company)->where('is_active', true)
            ->whereHas('modifier', fn ($query) => $query->where('purpose', ProductModifierPurpose::OrderModifier))
            ->with(['modifier', 'inventoryItem', 'ingredient.inventoryItem']);
        $option = $candidate instanceof ModifierOption
            ? $query->find($candidate->id)
            : (is_numeric($candidate) ? $query->find($candidate) : $query->where('ulid', $candidate)->first());

        if (! $option || ! $option->modifier->is_active) {
            throw new DomainException('El modificador elegido no está disponible.');
        }

        return $option;
    }

    private function resolveTopping(Company $company, mixed $data): ModifierOption
    {
        $candidate = is_array($data)
            ? ($data['option'] ?? $data['topping'] ?? $data['topping_id'] ?? null)
            : $data;
        $query = ModifierOption::query()->forCompany($company)->where('is_active', true)
            ->where('type', ModifierOptionType::Add)
            ->whereHas('modifier', fn ($query) => $query->where('is_active', true)
                ->where('purpose', ProductModifierPurpose::ToppingCatalog))
            ->with(['modifier', 'inventoryItem', 'sizeRules']);
        $option = $candidate instanceof ModifierOption
            ? $query->find($candidate->id)
            : (is_numeric($candidate) ? $query->find($candidate) : $query->where('ulid', $candidate)->first());

        if (! $option) {
            throw new DomainException('El topping elegido no está disponible.');
        }

        return $option;
    }

    /** @param list<array<string,mixed>> $sections */
    private function highestPricedSection(array $sections): array
    {
        return collect($sections)->reduce(function (?array $highest, array $section): array {
            if ($highest === null) {
                return $section;
            }

            $comparison = BigDecimal::of($section['variant']->price)
                ->compareTo(BigDecimal::of($highest['variant']->price));

            return $comparison > 0
                || ($comparison === 0 && $section['position'] < $highest['position'])
                    ? $section
                    : $highest;
        });
    }

    private function isStructuralBase($recipeItem): bool
    {
        return mb_strtolower(trim($recipeItem->ingredient->name)) === 'masa';
    }

    private function inventoryItemForRecipe($recipeItem): InventoryItem
    {
        $item = $recipeItem->ingredient->inventoryItem;
        if (! $item || ! $item->is_active) {
            throw new DomainException("El ingrediente {$recipeItem->ingredient->name} no tiene inventario activo.");
        }

        return $item;
    }

    private function decimal(BigRational $value, int $scale): BigDecimal
    {
        return $value->getNumerator()->toBigDecimal()
            ->dividedBy($value->getDenominator(), $scale, RoundingMode::HalfUp);
    }
}
