<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class InitializeMasaYManaBusinessAction
{
    public const EXPENSE_CATEGORY_NAMES = [
        'Servicios básicos',
        'Alquiler',
        'Sueldos y personal',
        'Limpieza',
        'Mantenimiento',
        'Transporte',
        'Delivery / movilidad',
        'Marketing y publicidad',
        'Insumos no inventariables',
        'Impuestos y tasas',
        'Otros gastos operativos',
    ];

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly InitializeStandardUnitsAction $initializeUnits,
        private readonly ImportMasaYManaMenuAction $importMenu,
    ) {}

    /**
     * @return array{
     *     units_created:int,units_reused:int,categories_created:int,categories_reused:int,
     *     expense_categories_created:int,expense_categories_reused:int,
     *     products_created:int,products_reused:int,variants_created:int,variants_reused:int,
     *     omitted:int,errors:int
     * }
     */
    public function execute(Company $company, Branch $branch, User $actor): array
    {
        $this->access->ensure($actor, $company, Permission::ManageCatalog);
        $this->access->ensure($actor, $company, Permission::ManageExpenseCategories);

        if (! $branch->is_active || (int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('La sucursal debe estar activa y pertenecer a la empresa seleccionada.');
        }

        return DB::transaction(function () use ($company, $branch, $actor): array {
            $standardSymbols = collect(InitializeStandardUnitsAction::definitions())->pluck('symbol');
            $existingUnitIds = Unit::query()->forCompany($company)
                ->whereIn('symbol', $standardSymbols)->pluck('id')->all();
            $units = $this->initializeUnits->execute($company, $actor);
            $unitsCreated = $units->whereNotIn('id', $existingUnitIds)->count();

            $categoryCounts = $this->initializeProductCategories($company);
            $expenseCategoryCounts = $this->initializeExpenseCategories($company);
            $menuCounts = $this->importMenu->execute($company, $branch);

            return [
                'units_created' => $unitsCreated,
                'units_reused' => $units->count() - $unitsCreated,
                ...$categoryCounts,
                ...$expenseCategoryCounts,
                ...$menuCounts,
            ];
        });
    }

    /** @return array{categories_created:int,categories_reused:int} */
    private function initializeProductCategories(Company $company): array
    {
        $created = 0;

        foreach (ImportMasaYManaMenuAction::CATEGORY_NAMES as $sortOrder => $name) {
            $category = Category::query()->forCompany($company)
                ->where('name', $name)->lockForUpdate()->first();
            if (! $category) {
                $category = new Category([
                    'company_id' => $company->getKey(),
                    'name' => $name,
                ]);
                $created++;
            }

            $category->is_active = true;
            $category->sort_order = $sortOrder;
            $category->save();
        }

        return [
            'categories_created' => $created,
            'categories_reused' => count(ImportMasaYManaMenuAction::CATEGORY_NAMES) - $created,
        ];
    }

    /** @return array{expense_categories_created:int,expense_categories_reused:int} */
    private function initializeExpenseCategories(Company $company): array
    {
        $created = 0;

        foreach (self::EXPENSE_CATEGORY_NAMES as $name) {
            $category = ExpenseCategory::query()->forCompany($company)
                ->where('name', $name)->lockForUpdate()->first();
            if (! $category) {
                $category = new ExpenseCategory([
                    'company_id' => $company->getKey(),
                    'name' => $name,
                ]);
                $created++;
            }

            $category->is_active = true;
            $category->save();
        }

        return [
            'expense_categories_created' => $created,
            'expense_categories_reused' => count(self::EXPENSE_CATEGORY_NAMES) - $created,
        ];
    }
}
