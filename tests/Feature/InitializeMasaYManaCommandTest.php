<?php

namespace Tests\Feature;

use App\Actions\ImportMasaYManaMenuAction;
use App\Actions\InitializeMasaYManaBusinessAction;
use App\Enums\MembershipRole;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitializeMasaYManaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_execution_initializes_exact_real_business_data_without_fictitious_operations(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        [$company, $branch] = $this->business();
        [$foreignCompany] = $this->business('Otra empresa', 'Otra sucursal');
        $userCount = User::query()->count();

        $this->artisan('app:initialize-masa-y-mana', [
            '--company' => $company->name,
            '--branch' => $branch->name,
        ])->expectsConfirmation(
            '¿Confirmas la inicialización idempotente de esta empresa y sucursal?',
            'yes',
        )->expectsOutputToContain('Inicialización completada correctamente.')
            ->expectsOutputToContain('Unidades creadas/reutilizadas')
            ->expectsOutputToContain('Errores')
            ->assertSuccessful();

        $this->assertSame($userCount, User::query()->count());
        $this->assertStandardUnits($company);
        $this->assertSame(
            ImportMasaYManaMenuAction::CATEGORY_NAMES,
            Category::query()->forCompany($company)->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(
            InitializeMasaYManaBusinessAction::EXPENSE_CATEGORY_NAMES,
            ExpenseCategory::query()->forCompany($company)->orderBy('id')->pluck('name')->all(),
        );
        $this->assertTrue(Category::query()->forCompany($company)->get()->every->is_active);
        $this->assertTrue(ExpenseCategory::query()->forCompany($company)->get()->every->is_active);

        $this->assertMenuCounts($company);
        $this->assertDatabaseMissing('products', [
            'company_id' => $company->id,
            'name' => 'VINO DE ESPECIALIDAD(CONSULTAR LA ELECCION DE TEMPORADA)',
        ]);

        $juiceCategory = Category::query()->forCompany($company)->where('name', 'Jugos naturales')->firstOrFail();
        $this->assertSame(2, Product::query()->forCompany($company)
            ->where('category_id', $juiceCategory->id)->where('is_active', false)->count());
        $this->assertSame(2, ProductVariant::query()->forCompany($company)
            ->whereHas('product', fn ($query) => $query->where('category_id', $juiceCategory->id))
            ->where('is_active', false)->count());

        $this->assertSame(13, InventoryItem::query()->forCompany($company)->count());
        $this->assertSame(0, InventoryStock::query()->forCompany($company)->count());
        $this->assertSame(0, InventoryBatch::query()->forCompany($company)->count());
        $this->assertSame(0, InventoryMovement::query()->forCompany($company)->count());
        $this->assertSame(0, Recipe::query()->forCompany($company)->count());
        $this->assertSame(0, RecipeItem::query()->forCompany($company)->count());
        $this->assertSame(0, CashRegister::query()->forCompany($company)->count());
        $this->assertSame(0, CashSession::query()->forCompany($company)->count());

        $this->assertSame(0, Unit::query()->forCompany($foreignCompany)->count());
        $this->assertSame(0, Category::query()->forCompany($foreignCompany)->count());
        $this->assertSame(0, ExpenseCategory::query()->forCompany($foreignCompany)->count());
        $this->assertSame(0, Product::query()->forCompany($foreignCompany)->count());
    }

    public function test_second_execution_reuses_everything_without_duplicates(): void
    {
        [$company, $branch] = $this->business();
        $arguments = ['--company' => $company->ulid, '--branch' => $branch->ulid];

        $this->artisan('app:initialize-masa-y-mana', $arguments)
            ->expectsConfirmation('¿Confirmas la inicialización idempotente de esta empresa y sucursal?', 'yes')
            ->assertSuccessful();
        $first = $this->counts($company);

        $this->artisan('app:initialize-masa-y-mana', $arguments)
            ->expectsConfirmation('¿Confirmas la inicialización idempotente de esta empresa y sucursal?', 'yes')
            ->expectsOutputToContain('Inicialización completada correctamente.')
            ->assertSuccessful();

        $this->assertSame($first, $this->counts($company));
        $this->assertSame([
            'units' => 5,
            'categories' => 5,
            'expense_categories' => 11,
            'products' => 32,
            'variants' => 66,
            'inventory_items' => 13,
        ], $first);
    }

    public function test_unique_active_company_and_branch_are_detected_and_confirmation_can_cancel(): void
    {
        [$company] = $this->business();

        $this->artisan('app:initialize-masa-y-mana')
            ->expectsConfirmation('¿Confirmas la inicialización idempotente de esta empresa y sucursal?', 'no')
            ->expectsOutputToContain('Inicialización cancelada')
            ->assertSuccessful();

        $this->assertSame(0, Unit::query()->forCompany($company)->count());
        $this->assertSame(0, Product::query()->forCompany($company)->count());
    }

    public function test_incompatible_standard_unit_fails_and_rolls_back_everything(): void
    {
        [$company, $branch] = $this->business();
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad equivocada',
            'symbol' => 'u',
            'type' => UnitType::Weight,
            'is_active' => true,
        ]);

        $this->artisan('app:initialize-masa-y-mana', [
            '--company' => $company->name,
            '--branch' => $branch->name,
        ])->expectsConfirmation(
            '¿Confirmas la inicialización idempotente de esta empresa y sucursal?',
            'yes',
        )->expectsOutputToContain('tipo incompatible')
            ->assertFailed();

        $this->assertSame(UnitType::Weight, $unit->refresh()->type);
        $this->assertSame(1, Unit::query()->forCompany($company)->count());
        $this->assertSame(0, Category::query()->forCompany($company)->count());
        $this->assertSame(0, ExpenseCategory::query()->forCompany($company)->count());
        $this->assertSame(0, Product::query()->forCompany($company)->count());
    }

    public function test_explicit_company_and_branch_keep_other_tenants_untouched(): void
    {
        [$company, $branch] = $this->business();
        [$otherCompany] = $this->business('Masa & Maña Norte', 'Sucursal Norte');
        Category::factory()->for($otherCompany)->create(['name' => 'Categoría ajena']);

        $this->artisan('app:initialize-masa-y-mana', [
            '--company' => (string) $company->id,
            '--branch' => (string) $branch->id,
        ])->expectsConfirmation(
            '¿Confirmas la inicialización idempotente de esta empresa y sucursal?',
            'yes',
        )->assertSuccessful();

        $this->assertMenuCounts($company);
        $this->assertSame(['Categoría ajena'], Category::query()->forCompany($otherCompany)->pluck('name')->all());
        $this->assertSame(0, Product::query()->forCompany($otherCompany)->count());
    }

    /** @return array{Company, Branch, User} */
    private function business(
        string $companyName = 'Masa & Maña',
        string $branchName = 'Masa & Maña Juan de la Rosa',
    ): array {
        $company = Company::factory()->create(['name' => $companyName, 'is_active' => true]);
        $branch = Branch::factory()->for($company)->create(['name' => $branchName, 'is_active' => true]);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create([
            'role' => MembershipRole::Owner,
            'is_active' => true,
        ]);

        return [$company, $branch, $owner];
    }

    private function assertStandardUnits(Company $company): void
    {
        $this->assertSame(5, Unit::query()->forCompany($company)->count());
        foreach ([
            ['Gramo', 'g', UnitType::Weight->value],
            ['Kilogramo', 'kg', UnitType::Weight->value],
            ['Mililitro', 'ml', UnitType::Volume->value],
            ['Litro', 'L', UnitType::Volume->value],
            ['Unidad', 'u', UnitType::Unit->value],
        ] as [$name, $symbol, $type]) {
            $this->assertDatabaseHas('units', [
                'company_id' => $company->id,
                'name' => $name,
                'symbol' => $symbol,
                'type' => $type,
                'is_active' => true,
            ]);
        }
    }

    private function assertMenuCounts(Company $company): void
    {
        $categories = Category::query()->forCompany($company)
            ->whereIn('name', ImportMasaYManaMenuAction::CATEGORY_NAMES)->get()->keyBy('name');
        $pizzaCategoryIds = $categories
            ->whereIn('name', ['Pizzas con maña', 'Las de siempre - con maña'])->pluck('id');
        $pizzaIds = Product::query()->forCompany($company)->whereIn('category_id', $pizzaCategoryIds)->pluck('id');

        $this->assertCount(17, $pizzaIds);
        $this->assertSame(51, ProductVariant::query()->forCompany($company)->whereIn('product_id', $pizzaIds)->count());
        foreach (['personal', 'mediana', 'familiar'] as $sizeKey) {
            $this->assertSame(17, ProductVariant::query()->forCompany($company)
                ->whereIn('product_id', $pizzaIds)->where('size_key', $sizeKey)->count());
        }
        $this->assertSame(10, Product::query()->forCompany($company)
            ->where('category_id', $categories->get('Gaseosas')->id)->count());
        $this->assertSame(2, Product::query()->forCompany($company)
            ->where('category_id', $categories->get('Jugos naturales')->id)->count());
        $this->assertSame(3, Product::query()->forCompany($company)
            ->where('category_id', $categories->get('Bebidas alcohólicas')->id)->count());
    }

    /** @return array<string, int> */
    private function counts(Company $company): array
    {
        $productIds = Product::query()->forCompany($company)->pluck('id');

        return [
            'units' => Unit::query()->forCompany($company)->count(),
            'categories' => Category::query()->forCompany($company)->count(),
            'expense_categories' => ExpenseCategory::query()->forCompany($company)->count(),
            'products' => $productIds->count(),
            'variants' => ProductVariant::query()->forCompany($company)->whereIn('product_id', $productIds)->count(),
            'inventory_items' => InventoryItem::query()->forCompany($company)->count(),
        ];
    }
}
