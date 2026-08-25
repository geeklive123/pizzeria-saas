<?php

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MasaYManaMenuSeeder extends Seeder
{
    private const COMPANY_NAME = 'Mi Pizzería';

    private const BRANCH_NAME = 'Principal';

    private const SOURCE_FILE = 'MENU_NORMALIZADO_PARA_CODEX.csv';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El menú de Masa & Maña solo puede cargarse en desarrollo o pruebas.');
        }

        $rows = $this->sourceRows();
        $company = Company::query()->where('name', self::COMPANY_NAME)->firstOrFail();
        Branch::query()->where('company_id', $company->getKey())
            ->where('name', self::BRANCH_NAME)
            ->firstOrFail();

        DB::transaction(function () use ($company, $rows): void {
            $categories = collect([
                'Pizzas con maña',
                'Las de siempre - con maña',
                'Gaseosas',
                'Jugos naturales',
                'Bebidas alcohólicas',
            ])->mapWithKeys(function (string $name, int $sortOrder) use ($company): array {
                $category = Category::query()->updateOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $name],
                    ['is_active' => true, 'sort_order' => $sortOrder],
                );

                return [$name => $category];
            });

            $unit = Unit::query()->where('company_id', $company->getKey())
                ->where('symbol', 'u')
                ->firstOrFail();

            foreach ($rows as $row) {
                if (blank($row['price_bs'])) {
                    continue;
                }

                $isPizza = $row['product_kind'] === 'pizza';
                $requiresReview = $row['product_kind'] === 'review';
                $description = filled($row['description']) ? $row['description'] : null;
                if ($requiresReview) {
                    $description = trim(collect([$description, $row['notes']])->filter()->implode("\n\n"));
                }

                // Exact product names identify rows owned by this explicit menu sync.
                // Price and source description are therefore safe to refresh idempotently;
                // historical order totals and pizza sections already persist snapshots.
                $product = Product::query()->updateOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $row['product_name']],
                    [
                        'category_id' => $categories->get($row['category'])->getKey(),
                        'description' => $description,
                        'type' => $isPizza ? ProductType::Pizza : ($requiresReview ? ProductType::Other : ProductType::Beverage),
                        'is_active' => true,
                    ],
                );

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

            // Preserve the demo flavor and its history, but keep its invented data
            // out of the real menu and the compatible flavor selector.
            $demoPepperoni = Product::query()->where('company_id', $company->getKey())
                ->where('name', 'Pizza Pepperoni')
                ->first();
            if ($demoPepperoni) {
                $demoPepperoni->update(['is_active' => false]);
                $demoPepperoni->variants()->update(['is_active' => false]);
            }
        });
    }

    /** @return list<array<string, string>> */
    private function sourceRows(): array
    {
        $path = base_path(self::SOURCE_FILE);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir '.self::SOURCE_FILE.'.');
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException('El CSV del menú no contiene encabezados.');
            }
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");

            $rows = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new RuntimeException('El CSV del menú contiene una fila con columnas inválidas.');
                }
                /** @var array<string, string> $row */
                $row = array_combine($header, array_map(static fn ($value): string => trim((string) $value), $values));
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if (count($rows) !== 67) {
            throw new RuntimeException('El CSV normalizado debe contener exactamente 67 filas de variantes y pendientes.');
        }

        return $rows;
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
