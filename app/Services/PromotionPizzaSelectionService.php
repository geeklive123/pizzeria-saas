<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Models\Promotion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class PromotionPizzaSelectionService
{
    public function __construct(private readonly PizzaCompositionService $composition) {}

    /**
     * @param  list<array<string, mixed>>  $pizzaData
     * @return array{pizzas:list<array<string,mixed>>,requirements:list<array<string,mixed>>,extras_total:string}
     */
    public function compose(Promotion $promotion, array $pizzaData, OrderType $fulfillment): array
    {
        $promotion->loadMissing(['company', 'selectionGroups.category', 'components.inventoryItem.unit']);
        $groups = $promotion->selectionGroups->keyBy('ulid');
        if ($groups->isEmpty()) {
            if ($pizzaData !== []) {
                throw new DomainException('Esta promoción no admite selección de pizzas.');
            }

            return ['pizzas' => [], 'requirements' => [], 'extras_total' => '0.00'];
        }

        $submitted = collect($pizzaData)->groupBy(fn (array $pizza): string => (string) ($pizza['group'] ?? ''));
        if ($submitted->keys()->diff($groups->keys())->isNotEmpty()) {
            throw new DomainException('La selección contiene un grupo de pizzas que no pertenece a la promoción.');
        }

        $pizzas = [];
        $requirements = [];
        $extrasTotal = BigDecimal::zero();
        foreach ($groups as $group) {
            if (! $group->category->is_active) {
                throw new DomainException('La categoría permitida para la promoción ya no está activa.');
            }
            $groupPizzas = $submitted->get($group->ulid, collect())->values();
            if ($groupPizzas->count() !== $group->quantity) {
                throw new DomainException("Debes configurar exactamente {$group->quantity} pizza(s) del grupo {$group->category->name}.");
            }

            $usedVariants = [];
            foreach ($groupPizzas as $position => $data) {
                $sections = array_values($data['sections'] ?? []);
                $modifiers = array_values(array_filter($data['modifiers'] ?? [], fn ($modifier): bool => filled($modifier['option'] ?? null)));
                $toppings = array_values(array_filter($data['toppings'] ?? [], fn ($topping): bool => filled($topping)));
                if (! $group->allow_fusions && count($sections) !== 1) {
                    throw new DomainException('Este grupo no permite pizzas fusionadas.');
                }
                $result = $this->composition->compose($promotion->company, $sections, $modifiers, $fulfillment, $toppings);
                if (($result['snapshot']['inventory_status'] ?? null) !== 'configured') {
                    throw new DomainException('Todas las pizzas de una promoción deben tener una receta activa configurada.');
                }
                if (! $group->allow_extras && ($toppings !== [] || collect($result['modifiers'])->contains(fn (array $modifier): bool => $modifier['type']->value === 'add'))) {
                    throw new DomainException('Este grupo no permite extras.');
                }
                if (! $group->allow_removals && collect($result['modifiers'])->contains(fn (array $modifier): bool => $modifier['type']->value === 'remove')) {
                    throw new DomainException('Este grupo no permite remover ingredientes.');
                }
                foreach ($result['sections'] as $section) {
                    $variant = $section['variant'];
                    if ((int) $variant->product->category_id !== (int) $group->category_id) {
                        throw new DomainException('Una pizza seleccionada no pertenece a la categoría permitida.');
                    }
                    if ($result['snapshot']['size_key'] !== $group->size_key) {
                        throw new DomainException('Una pizza seleccionada no corresponde al tamaño permitido.');
                    }
                    if (! $group->allow_repeated_flavors && isset($usedVariants[$variant->getKey()])) {
                        throw new DomainException('Este grupo no permite repetir sabores.');
                    }
                    $usedVariants[$variant->getKey()] = true;
                }

                $basePrice = collect($result['sections'])->reduce(function (BigDecimal $highest, array $section): BigDecimal {
                    $candidate = BigDecimal::of($section['unit_price_snapshot']);

                    return $candidate->isGreaterThan($highest) ? $candidate : $highest;
                }, BigDecimal::zero());
                $extras = BigDecimal::of($result['unit_price'])->minus($basePrice)->toScale(2, RoundingMode::HalfUp);
                $extrasTotal = $extrasTotal->plus($extras);

                foreach ($result['requirements'] as $requirement) {
                    $id = $requirement['inventory_item']->getKey();
                    $requirements[$id] ??= [
                        'inventory_item_id' => $id,
                        'inventory_item_ulid' => $requirement['inventory_item']->ulid,
                        'inventory_item_name' => $requirement['inventory_item']->name,
                        'unit_id' => $requirement['inventory_item']->unit_id,
                        'quantity' => BigDecimal::zero(),
                    ];
                    $requirements[$id]['quantity'] = $requirements[$id]['quantity']->plus($requirement['quantity']);
                }

                $pizzaSnapshot = $result['snapshot'];
                $pizzaSnapshot['sections'] = collect($result['sections'])->map(fn (array $section): array => [
                    'position' => $section['position'],
                    'fraction' => $section['fraction_numerator'].'/'.$section['fraction_denominator'],
                    'product_id' => $section['variant']->product->getKey(),
                    'product_ulid' => $section['variant']->product->ulid,
                    'product' => $section['product_name_snapshot'],
                    'variant_id' => $section['variant']->getKey(),
                    'variant_ulid' => $section['variant']->ulid,
                    'variant' => $section['variant_name_snapshot'],
                    'unit_price' => $section['unit_price_snapshot'],
                ])->all();
                $pizzaSnapshot['modifiers'] = collect($result['modifiers'])->map(fn (array $modifier): array => [
                    'modifier_option_id' => $modifier['option']->getKey(),
                    'modifier_option_ulid' => $modifier['option']->ulid,
                    'purpose' => $modifier['purpose']->value,
                    'section_position' => $modifier['section_position'],
                    'type' => $modifier['type']->value,
                    'name' => $modifier['name_snapshot'],
                    'price_delta' => $modifier['price_delta_snapshot'],
                    'quantity' => $modifier['quantity_snapshot'],
                    'inventory_item_id' => $modifier['inventory_item']?->getKey(),
                    'inventory_item' => $modifier['inventory_item']?->name,
                    'size_key' => $modifier['size_key_snapshot'],
                    'price_source' => $modifier['price_source'],
                ])->all();
                $pizzas[] = [
                    'group_ulid' => $group->ulid,
                    'group_category' => $group->category->name,
                    'position' => $position + 1,
                    'extras_price' => (string) $extras,
                    ...$pizzaSnapshot,
                ];
            }
        }

        return [
            'pizzas' => $pizzas,
            'requirements' => collect($requirements)->sortKeys()->map(fn (array $requirement): array => [
                ...$requirement,
                'quantity' => (string) $requirement['quantity']->toScale(3, RoundingMode::HalfUp),
            ])->values()->all(),
            'extras_total' => (string) $extrasTotal->toScale(2, RoundingMode::HalfUp),
        ];
    }
}
