<?php

namespace Tests\Feature;

use App\Actions\OpenCashSessionAction;
use App\Enums\MembershipRole;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Membership;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_cash_onboarding_offers_owner_a_direct_action_and_guides_back_to_opening(): void
    {
        [$company, $branch, $owner] = $this->context();

        $this->asUser($owner, $company, $branch)->get(route('cash.open.form'))
            ->assertOk()
            ->assertSee('Configura la primera caja')
            ->assertSee('Crear caja')
            ->assertSee(route('cash-registers.create', ['onboarding' => 1]));

        $this->asUser($owner, $company, $branch)
            ->get(route('cash-registers.create', ['onboarding' => 1]))
            ->assertOk()->assertSee('Caja Principal')->assertSee('Crear y continuar');

        $this->asUser($owner, $company, $branch)->post(route('cash-registers.store'), [
            'name' => 'Caja Principal',
            'is_active' => '1',
            'onboarding' => '1',
        ])->assertRedirect(route('cash.open.form'));

        $this->assertDatabaseHas('cash_registers', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);
    }

    public function test_cash_onboarding_tells_cashier_to_request_permission(): void
    {
        [$company, $branch] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier);

        $this->asUser($cashier, $company, $branch)->get(route('cash.open.form'))
            ->assertOk()
            ->assertSee('No tienes permiso para crear cajas')
            ->assertDontSee('Crear y continuar');

        $this->asUser($cashier, $company, $branch)
            ->get(route('cash-registers.index'))->assertForbidden();
    }

    public function test_register_management_is_branch_scoped_and_protects_an_open_register(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create(['is_active' => true]);
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja Uno',
            'is_active' => true,
        ]);
        $foreignBranchRegister = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Caja Otra',
            'is_active' => true,
        ]);

        $this->asUser($owner, $company, $branch)->get(route('cash-registers.index'))
            ->assertOk()->assertSee('Caja Uno')->assertDontSee('Caja Otra');
        $this->asUser($owner, $company, $branch)
            ->get(route('cash-registers.edit', $foreignBranchRegister->ulid))->assertNotFound();

        $this->asUser($owner, $company, $branch)->put(route('cash-registers.update', $register->ulid), [
            'name' => 'Caja Norte',
            'is_active' => '1',
        ])->assertRedirect(route('cash-registers.index'));
        $this->assertSame('Caja Norte', $register->refresh()->name);

        app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $this->asUser($owner, $company, $branch)
            ->post(route('cash-registers.toggle', $register->ulid))
            ->assertSessionHasErrors('cash_register');
        $this->assertTrue($register->refresh()->is_active);
    }

    public function test_category_crud_orders_toggles_and_isolates_companies(): void
    {
        [$company, $branch, $owner] = $this->context();
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $foreign = Category::factory()->for($foreignCompany)->create(['name' => 'Oculta']);

        $this->asUser($owner, $company, $branch)->post(route('categories.store'), [
            'name' => 'Pizzas',
            'description' => 'Carta principal',
            'sort_order' => 3,
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));

        $category = Category::query()->forCompany($company)->where('name', 'Pizzas')->firstOrFail();
        $this->asUser($owner, $company, $branch)->put(route('categories.update', $category->ulid), [
            'name' => 'Pizzas especiales',
            'description' => 'Carta actualizada',
            'sort_order' => 1,
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));
        $this->asUser($owner, $company, $branch)->post(route('categories.toggle', $category->ulid))
            ->assertRedirect();
        $this->assertFalse($category->refresh()->is_active);
        $this->assertSame(1, $category->sort_order);

        $this->asUser($owner, $company, $branch)
            ->get(route('categories.edit', $foreign->ulid))->assertNotFound();
        $this->asUser($owner, $company, $branch)->get(route('categories.index'))
            ->assertOk()->assertSee('Pizzas especiales')->assertDontSee('Oculta');
    }

    public function test_catalog_management_requires_owner_or_admin_permissions(): void
    {
        [$company, $branch] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);
        $cashier = $this->member($company, MembershipRole::Cashier);

        $this->asUser($admin, $company, $branch)->get(route('cash-registers.index'))->assertOk();
        $this->asUser($admin, $company, $branch)->get(route('categories.create'))->assertOk();
        $this->asUser($admin, $company, $branch)->get(route('units.create'))->assertOk();
        $this->asUser($cashier, $company, $branch)->get(route('categories.index'))->assertOk();
        $this->asUser($cashier, $company, $branch)->post(route('categories.store'), [
            'name' => 'Sin permiso',
            'sort_order' => 0,
            'is_active' => '1',
        ])->assertForbidden();
        $this->asUser($cashier, $company, $branch)
            ->post(route('units.initialize'))->assertForbidden();
    }

    public function test_standard_units_are_exact_idempotent_and_company_scoped(): void
    {
        [$company, $branch, $owner] = $this->context();
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        Unit::factory()->for($foreignCompany)->create([
            'name' => 'Unidad extranjera',
            'symbol' => 'u',
            'type' => UnitType::Unit,
        ]);

        $this->asUser($owner, $company, $branch)->post(route('units.initialize'))
            ->assertRedirect()->assertSessionHas('success');
        $this->asUser($owner, $company, $branch)->post(route('units.initialize'))
            ->assertRedirect()->assertSessionHas('success');

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
        $this->assertSame(1, Unit::query()->forCompany($foreignCompany)->count());

        $kilogram = Unit::query()->forCompany($company)->where('symbol', 'kg')->firstOrFail();
        $this->asUser($owner, $company, $branch)->put(route('units.update', $kilogram->id), [
            'name' => 'Kilogramo comercial',
            'symbol' => 'kg',
            'type' => UnitType::Weight->value,
            'is_active' => '1',
        ])->assertRedirect(route('units.index'));
        $this->asUser($owner, $company, $branch)->post(route('units.toggle', $kilogram->id))
            ->assertRedirect();
        $this->assertFalse($kilogram->refresh()->is_active);
    }

    public function test_used_unit_keeps_its_type_and_missing_master_data_shows_actions(): void
    {
        [$company, $branch, $owner] = $this->context();

        $this->asUser($owner, $company, $branch)->get(route('ingredients.create'))
            ->assertOk()->assertSee('Faltan unidades activas')->assertSee(route('units.index'));
        $this->asUser($owner, $company, $branch)->get(route('products.create'))
            ->assertOk()->assertSee('No hay categorías activas')->assertSee(route('categories.create'));

        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad',
            'symbol' => 'u',
            'type' => UnitType::Unit,
        ]);
        Ingredient::factory()->for($company)->for($unit)->create();

        $this->asUser($owner, $company, $branch)->put(route('units.update', $unit->id), [
            'name' => 'Unidad',
            'symbol' => 'u',
            'type' => UnitType::Weight->value,
            'is_active' => '1',
        ])->assertSessionHasErrors('unit');

        $this->assertSame(UnitType::Unit, $unit->refresh()->type);
    }

    public function test_unit_routes_do_not_expose_another_company(): void
    {
        [$company, $branch, $owner] = $this->context();
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $foreignUnit = Unit::factory()->for($foreignCompany)->create();

        $this->asUser($owner, $company, $branch)
            ->get(route('units.edit', $foreignUnit->id))->assertNotFound();
    }

    private function context(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $branch = Branch::factory()->for($company)->create(['is_active' => true]);
        $owner = $this->member($company, MembershipRole::Owner);

        return [$company, $branch, $owner];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create([
            'role' => $role,
            'is_active' => true,
        ]);

        return $user;
    }

    private function asUser(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
