<?php

namespace App\Actions;

use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportMasaYManaMenuAction
{
    public const SOURCE_FILE = 'MENU_NORMALIZADO_PARA_CODEX.csv';

    public const CATEGORY_NAMES = [
        'Pizzas con maña',
        'Las de siempre - con maña',
        'Gaseosas',
        'Jugos naturales',
        'Bebidas alcohólicas',
    ];

    /** @return array{rows:int,products:int,variants:int,omitted:int} */
    public function preview(): array
    {
        $rows = $this->sourceRows();

        return [
            'rows' => count($rows),
            'products' => collect($rows)->whereNotNull('price_bs')->pluck('product_name')->unique()->count(),
            'variants' => collect($rows)->whereNotNull('price_bs')->count(),
            'omitted' => collect($rows)->whereNull('price_bs')->count(),
        ];
    }

    /**
     * @return array{
     *     products_created:int,products_reused:int,variants_created:int,variants_reused:int,
     *     omitted:int,errors:int
     * }
     */
    public function execute(Company $company, Branch $branch): array
    {
        if (! $company->is_active
            || ! $branch->is_active
            || (int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('La empresa y sucursal seleccionadas deben estar activas y relacionadas.');
        }

        $rows = $this->sourceRows();

        return DB::transaction(function () use ($company, $rows): array {
            $categories = Category::query()->forCompany($company)
                ->whereIn('name', self::CATEGORY_NAMES)->get()->keyBy('name');
            if ($categories->count() !== count(self::CATEGORY_NAMES)
                || $categories->contains(fn (Category $category): bool => ! $category->is_active)) {
                throw new DomainException('Las cinco categorías del menú deben existir y estar activas antes de importar.');
            }

            $unit = Unit::query()->forCompany($company)->where('symbol', 'u')->first();
            if (! $unit || ! $unit->is_active || $unit->type !== UnitType::Unit) {
                throw new DomainException('La unidad activa u de tipo unit debe existir antes de importar.');
            }

            $productIds = [];
            $createdProductIds = [];
            $variantIds = [];
            $createdVariantIds = [];
            $omitted = 0;

            foreach ($rows as $row) {
                if ($row['price_bs'] === null) {
                    $omitted++;

                    continue;
                }

                $isPizza = $row['product_kind'] === 'pizza';
                $requiresReview = $row['product_kind'] === 'review';
                $description = filled($row['description']) ? $row['description'] : null;
                if ($requiresReview) {
                    $description = trim(collect([$description, $row['notes']])->filter()->implode(PHP_EOL.PHP_EOL));
                }

                $product = Product::query()->updateOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $row['product_name']],
                    [
                        'category_id' => $categories->get($row['category'])->getKey(),
                        'description' => $description,
                        'type' => $isPizza ? ProductType::Pizza : ($requiresReview ? ProductType::Other : ProductType::Beverage),
                        'is_active' => ! $requiresReview,
                    ],
                );
                $productIds[$product->getKey()] = true;
                if ($product->wasRecentlyCreated) {
                    $createdProductIds[$product->getKey()] = true;
                }

                $variant = ProductVariant::query()->updateOrCreate(
                    ['product_id' => $product->getKey(), 'name' => $row['variant_name']],
                    [
                        'company_id' => $company->getKey(),
                        'size_key' => $row['size_key'],
                        'price' => $this->price($row['price_bs']),
                        'requires_preparation' => $isPizza,
                        'is_active' => ! $requiresReview,
                        'sort_order' => $this->variantSortOrder($row['size_key']),
                    ],
                );
                $variantIds[$variant->getKey()] = true;
                if ($variant->wasRecentlyCreated) {
                    $createdVariantIds[$variant->getKey()] = true;
                }

                if ($row['product_kind'] === 'direct_sale') {
                    InventoryItem::query()->updateOrCreate(
                        ['product_variant_id' => $variant->getKey()],
                        [
                            'company_id' => $company->getKey(),
                            'unit_id' => $unit->getKey(),
                            'ingredient_id' => null,
                            'name' => $row['product_name'],
                            'is_active' => true,
                        ],
                    );
                }
            }

            $demoPepperoni = Product::query()->forCompany($company)->where('name', 'Pizza Pepperoni')->first();
            if ($demoPepperoni) {
                $demoPepperoni->update(['is_active' => false]);
                $demoPepperoni->variants()->update(['is_active' => false]);
            }

            return [
                'products_created' => count($createdProductIds),
                'products_reused' => count($productIds) - count($createdProductIds),
                'variants_created' => count($createdVariantIds),
                'variants_reused' => count($variantIds) - count($createdVariantIds),
                'omitted' => $omitted,
                'errors' => 0,
            ];
        });
    }

    /** @return list<array<string, string|null>> */
    private function sourceRows(): array
    {
        $path = base_path(self::SOURCE_FILE);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir '.self::SOURCE_FILE.'.');
        }

        try {
            $header = fgetcsv($handle, null, ',', chr(34), '');
            if ($header === false) {
                throw new RuntimeException('El CSV del menú no contiene encabezados.');
            }
            $header[0] = ltrim($header[0], pack('CCC', 0xEF, 0xBB, 0xBF));

            $expectedHeader = [
                'category',
                'product_name',
                'description',
                'variant_name',
                'size_key',
                'price_bs',
                'product_kind',
                'goes_to_kitchen',
                'recipe_status',
                'notes',
            ];
            if ($header !== $expectedHeader) {
                throw new RuntimeException('Los encabezados del CSV del menú no coinciden con el formato validado.');
            }

            $rows = [];
            while (($values = fgetcsv($handle, null, ',', chr(34), '')) !== false) {
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new RuntimeException('El CSV del menú contiene una fila con columnas inválidas.');
                }

                /** @var array<string, string> $row */
                $row = array_combine($header, array_map(static fn ($value): string => trim((string) $value), $values));
                $row['price_bs'] = filled($row['price_bs']) ? $row['price_bs'] : null;
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        $this->validateRows($rows);

        return $rows;
    }

    /** @param list<array<string, string|null>> $rows */
    private function validateRows(array $rows): void
    {
        $collection = collect($rows);
        $priced = $collection->whereNotNull('price_bs');
        $pizzaRows = $priced->where('product_kind', 'pizza');
        $directRows = $priced->where('product_kind', 'direct_sale');
        $reviewRows = $priced->where('product_kind', 'review');

        $validStructure = $collection->count() === 67
            && $priced->count() === 66
            && $collection->whereNull('price_bs')->count() === 1
            && $priced->pluck('product_name')->unique()->count() === 32
            && $pizzaRows->count() === 51
            && $pizzaRows->pluck('product_name')->unique()->count() === 17
            && $directRows->count() === 13
            && $reviewRows->count() === 2
            && $priced->map(fn (array $row): string => $row['product_name'].'|'.$row['variant_name'])
                ->unique()->count() === 66;

        if (! $validStructure) {
            throw new RuntimeException('El CSV no conserva los 32 productos, 66 variantes y una omisión validados.');
        }

        $categoryCounts = $priced->countBy('category')->all();
        $expectedCounts = [
            'Pizzas con maña' => 27,
            'Las de siempre - con maña' => 24,
            'Gaseosas' => 10,
            'Jugos naturales' => 2,
            'Bebidas alcohólicas' => 3,
        ];
        if ($categoryCounts !== $expectedCounts) {
            throw new RuntimeException('Las categorías o cantidades del CSV no coinciden con el menú validado.');
        }

        foreach ($pizzaRows->groupBy('product_name') as $variants) {
            if ($variants->pluck('variant_name')->sort()->values()->all() !== ['Familiar', 'Mediana', 'Personal']
                || $variants->pluck('size_key')->sort()->values()->all() !== ['familiar', 'mediana', 'personal']
                || $variants->contains(fn (array $row): bool => $row['recipe_status'] !== 'pending_quantities')) {
                throw new RuntimeException('Cada pizza debe conservar Personal, Mediana y Familiar sin receta inventada.');
            }
        }

        if ($priced->contains(fn (array $row): bool => ! preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $row['price_bs']))) {
            throw new RuntimeException('El CSV contiene un precio inválido.');
        }
    }

    private function price(string $price): string
    {
        return str_contains($price, '.') ? $price : $price.'.00';
    }

    private function variantSortOrder(string $sizeKey): int
    {
        return match ($sizeKey) {
            'personal' => 0,
            'mediana' => 1,
            'familiar' => 2,
            default => 0,
        };
    }
}
