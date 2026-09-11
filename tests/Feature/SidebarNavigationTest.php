<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_and_admin_see_authorized_modules_in_workflow_groups(): void
    {
        [$company, $branch, $owner] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);

        foreach ([$owner, $admin] as $user) {
            $this->actingInContext($user, $company, $branch)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSeeInOrder([
                    'Principal',
                    'Ventas y atención',
                    'Operación',
                    'Productos y recetas',
                    'Inventario y compras',
                    'Administración',
                ])
                ->assertSee('Nueva venta')
                ->assertSee('Pedidos')
                ->assertSee('Mesas')
                ->assertSee('Menú')
                ->assertSee('Caja')
                ->assertSee('Cocina')
                ->assertSee('Preparaciones')
                ->assertSee('Productos')
                ->assertSee('Recetas')
                ->assertSee('Inventario')
                ->assertSee('Compras')
                ->assertSee('Proveedores')
                ->assertSee('Gastos')
                ->assertSee('Usuarios')
                ->assertSee('Configuración')
                ->assertSee('Reportes');
        }
    }

    public function test_active_route_opens_its_group_and_marks_the_link_semantically(): void
    {
        [$company, $branch, $owner] = $this->context();

        foreach ([
            ['ingredients.index', 'catalog'],
            ['cash.index', 'sales'],
            ['orders.index', 'sales'],
            ['inventory.index', 'inventory'],
        ] as [$routeName, $group]) {
            $this->actingInContext($owner, $company, $branch)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('data-nav-key="'.$group.'" data-active="true" data-expanded="true"', false)
                ->assertSee('aria-current="page" data-nav-active', false);
        }
    }

    public function test_cashier_keeps_the_existing_reduced_navigation_without_new_links(): void
    {
        [$company, $branch] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier);

        $this->actingInContext($cashier, $company, $branch)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee('data-nav-key="sales" data-active="true" data-expanded="true"', false)
            ->assertSee('Nueva venta')
            ->assertSee('Pedidos')
            ->assertSee('Menú')
            ->assertSee('Caja')
            ->assertSee('Inventario')
            ->assertSee('Reportes')
            ->assertDontSee('Inicio')
            ->assertDontSee('href="'.route('tables.index').'"', false)
            ->assertDontSee('href="'.route('products.index').'"', false)
            ->assertDontSee('href="'.route('purchases.index').'"', false)
            ->assertDontSee('href="'.route('expenses.index').'"', false)
            ->assertDontSee('href="'.route('memberships.index').'"', false)
            ->assertDontSee('href="'.route('settings.edit').'"', false);
    }

    public function test_empty_groups_are_not_rendered_for_kitchen_role(): void
    {
        [$company, $branch] = $this->context();
        $kitchen = $this->member($company, MembershipRole::Kitchen);

        $this->actingInContext($kitchen, $company, $branch)
            ->get(route('kitchen.index'))
            ->assertOk()
            ->assertSee('data-nav-key="operations"', false)
            ->assertDontSee('data-nav-key="sales"', false)
            ->assertDontSee('data-nav-key="administration"', false);
    }

    public function test_sidebar_markup_supports_keyboard_and_mobile_off_canvas_navigation(): void
    {
        [$company, $branch, $owner] = $this->context();

        $this->actingInContext($owner, $company, $branch)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Menú principal"', false)
            ->assertSee('max-w-[calc(100vw-2rem)]', false)
            ->assertSee('aria-controls="sidebar" aria-expanded="false"', false)
            ->assertSee('data-nav-group-toggle', false)
            ->assertSee('aria-controls="sidebar-group-principal"', false)
            ->assertSee(' inert ', false)
            ->assertSee('aria-expanded="true"', false);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = $this->member($company, MembershipRole::Owner);

        return [$company, $branch, $owner];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
