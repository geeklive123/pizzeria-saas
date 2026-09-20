<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ConfigureNaturalJuiceFlavorsAction
{
    public const PRODUCT_NAME = 'JUGOS NATURALES CON FRUTA DE TEMPORADA(CONSULTAR OPCIONES DISPONIBLES) 1Lts. FINES DE SEMANA';

    public const HISTORICAL_VARIANT_NAME = 'Única';

    public const INITIAL_STOCK_OPERATION = 'natural-juice-flavor-initial-stock-v1';

    public const FLAVORS = [
        'Piña' => '8.000',
        'Maracuyá' => '11.000',
        'Tumbo' => '12.000',
        'Copoazú' => '13.000',
    ];

    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly CompanyAccessService $access,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Company $company, Branch $branch): array
    {
        $this->validateBranch($company, $branch);
        [$product, $historical] = $this->productAndHistoricalVariant($company);
        $existing = $product->variants()->whereIn('name', array_keys(self::FLAVORS))
            ->with(['inventoryItem.inventoryStocks' => fn ($query) => $query->where('branch_id', $branch->getKey())])
            ->get()->keyBy('name');

        return [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'historical_variant_id' => $historical->getKey(),
            'historical_variant_name' => $historical->name,
            'historical_order_items' => $historical->orderItems()->count(),
            'price' => $historical->price,
            'flavors' => collect(self::FLAVORS)->map(function (string $initialStock, string $name) use ($existing, $branch, $company): array {
                $variant = $existing->get($name);
                $item = $variant?->inventoryItem;
                $stock = $item?->inventoryStocks->firstWhere('branch_id', $branch->getKey());

                return [
                    'name' => $name,
                    'initial_stock' => $initialStock,
                    'variant_id' => $variant?->getKey(),
                    'inventory_item_id' => $item?->getKey(),
                    'current_stock' => $stock?->quantity ?? '0.000',
                    'initial_stock_loaded' => $variant && $item
                        ? $this->initialMovementExists($company, $branch, $variant, $item)
                        : false,
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function execute(Company $company, Branch $branch, User $actor): array
    {
        $this->validateBranch($company, $branch);
        $this->access->ensure($actor, $company, Permission::ManageCatalog);
        $this->access->ensure($actor, $company, Permission::ManageInventory);

        return DB::transaction(function () use ($company, $branch, $actor): array {
            [$product, $historical] = $this->productAndHistoricalVariant($company, true);
            $unit = Unit::query()->forCompany($company)
                ->where('symbol', 'u')->where('is_active', true)->lockForUpdate()->first();
            if (! $unit || $unit->type !== UnitType::Unit) {
                throw new DomainException('La empresa necesita una unidad activa u de tipo unit.');
            }
            $historical->forceFill(['is_active' => false])->save();
            $historicalRecipe = $historical->recipe()->lockForUpdate()->first();
            $historicalRecipe?->forceFill(['is_active' => false])->save();
            $counts = [
                'variants_created' => 0, 'variants_reused' => 0,
                'inventory_items_created' => 0, 'inventory_items_reused' => 0,
                'stock_loaded' => 0, 'stock_skipped' => 0,
            ];
            $configured = [];
            foreach (self::FLAVORS as $name => $initialStock) {
                $variant = ProductVariant::query()->where('product_id', $product->getKey())
                    ->where('name', $name)->lockForUpdate()->first();
                $created = ! $variant;
                $variant ??= new ProductVariant;
                $variant->fill([
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'name' => $name,
                    'size_key' => $historical->size_key,
                    'price' => $historical->price,
                    'requires_preparation' => $historical->requires_preparation,
                    'is_active' => true,
                    'sort_order' => array_search($name, array_keys(self::FLAVORS), true) + 1,
                ])->save();
                $counts[$created ? 'variants_created' : 'variants_reused']++;
                if ($variant->recipe()->exists()) {
                    throw new DomainException('La variante '.$name.' no puede usar una receta.');
                }
                $item = InventoryItem::query()->where('product_variant_id', $variant->getKey())
                    ->lockForUpdate()->first();
                $itemCreated = ! $item;
                $item ??= new InventoryItem;
                if ($item->exists && ((int) $item->company_id !== (int) $company->getKey()
                    || $item->ingredient_id !== null)) {
                    throw new DomainException('El inventario de '.$name.' no representa exclusivamente su variante.');
                }
                $item->fill([
                    'company_id' => $company->getKey(),
                    'unit_id' => $unit->getKey(),
                    'ingredient_id' => null,
                    'product_variant_id' => $variant->getKey(),
                    'name' => 'Jugo Natural - '.$name,
                    'is_active' => true,
                ])->save();
                $counts[$itemCreated ? 'inventory_items_created' : 'inventory_items_reused']++;
                if ($this->initialMovementExists($company, $branch, $variant, $item, true)) {
                    $counts['stock_skipped']++;
                } else {
                    $hasMovements = InventoryMovement::query()->forCompany($company)
                        ->where('branch_id', $branch->getKey())
                        ->where('inventory_item_id', $item->getKey())
                        ->lockForUpdate()->exists();
                    if ($hasMovements) {
                        throw new DomainException($item->name.' ya tiene movimientos sin el marcador inicial.');
                    }
                    $this->applyMovement->execute(
                        $company,
                        $branch,
                        $item,
                        InventoryMovementType::AdjustmentIn,
                        $initialStock,
                        '0.000000',
                        $actor,
                        reason: 'Stock inicial de jugos naturales por sabor',
                        referenceType: ProductVariant::class,
                        referenceId: $variant->getKey(),
                        metadata: [
                            'operation_key' => self::INITIAL_STOCK_OPERATION,
                            'flavor' => $name,
                        ],
                    );
                    $counts['stock_loaded']++;
                }
                $configured[] = [
                    'name' => $name,
                    'variant_id' => $variant->getKey(),
                    'inventory_item_id' => $item->getKey(),
                    'initial_stock' => $initialStock,
                ];
            }

            return [
                'product_id' => $product->getKey(),
                'historical_variant_id' => $historical->getKey(),
                'price' => $historical->price,
                ...$counts,
                'flavors' => $configured,
            ];
        }, attempts: 3);
    }

    /** @return array{Product, ProductVariant} */
    private function productAndHistoricalVariant(Company $company, bool $lock = false): array
    {
        $products = Product::query()->forCompany($company)->where('name', self::PRODUCT_NAME);
        $product = ($lock ? $products->lockForUpdate() : $products)->first();
        if (! $product || ! $product->is_active) {
            throw new DomainException('No se encontró activo el producto actual de Jugos Naturales.');
        }
        $variants = ProductVariant::query()->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->where('name', self::HISTORICAL_VARIANT_NAME);
        $historical = ($lock ? $variants->lockForUpdate() : $variants)->first();
        if (! $historical) {
            throw new DomainException('No se encontró la variante histórica Única de Jugos Naturales.');
        }

        return [$product, $historical];
    }

    private function validateBranch(Company $company, Branch $branch): void
    {
        if (! $company->is_active || ! $branch->is_active
            || (int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('La empresa y sucursal deben estar activas y relacionadas.');
        }
    }

    private function initialMovementExists(
        Company $company,
        Branch $branch,
        ProductVariant $variant,
        InventoryItem $item,
        bool $lock = false,
    ): bool {
        $query = InventoryMovement::query()->forCompany($company)
            ->where('branch_id', $branch->getKey())
            ->where('inventory_item_id', $item->getKey())
            ->where('type', InventoryMovementType::AdjustmentIn->value)
            ->where('reference_type', ProductVariant::class)
            ->where('reference_id', $variant->getKey());

        return ($lock ? $query->lockForUpdate() : $query)->get()
            ->contains(fn (InventoryMovement $movement): bool => data_get($movement->metadata, 'operation_key') === self::INITIAL_STOCK_OPERATION);
    }
}
