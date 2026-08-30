<?php

namespace Tests\Feature;

use App\Actions\CloseCashSessionAction;
use App\Actions\ManualCashMovementAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OwnerCashWithdrawalAction;
use App\Enums\CashClosingBalanceStatus;
use App\Enums\CashMovementType;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Membership;
use App\Models\MembershipPermissionOverride;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UserPermissionsAndCashAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_cashier_defaults_keep_sales_tables_orders_and_cash_but_exclude_privileged_modules(): void
    {
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::ViewOrders));
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::ViewTables));
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::ViewCash));
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::CreatePayments));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::RegisterManualCashMovements));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::AuthorizeCashWithdrawals));
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::ViewInventory));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ManageInventory));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ViewExpenses));
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::ViewReports));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ViewFinancialReports));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ExportReports));

        [$company, $branch, $owner, $admin, $cashier] = $this->context();
        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()->assertSee('Venta')->assertSee('Pedidos')->assertSee('Caja')
            ->assertSee('Inventario')->assertSee('Reportes')->assertDontSee('Mesas')
            ->assertDontSee('Usuarios')->assertDontSee('Configuración')->assertDontSee('Recetas')
            ->assertDontSee('Compras')->assertDontSee('Gastos')
            ->assertDontSee('Proveedores')->assertDontSee('Categorías de gasto');
        $this->actingInContext($cashier, $company, $branch)->get(route('tables.index'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('inventory.index'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('reports.sales'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('reports.index'))->assertForbidden();
        foreach ([$owner, $admin] as $manager) {
            $this->actingInContext($manager, $company, $branch)->get(route('cash.index'))
                ->assertOk()->assertSee('Mesas');
        }
        foreach (['memberships.index', 'settings.edit', 'recipes.index', 'purchases.index', 'expenses.index', 'suppliers.index', 'expense-categories.index'] as $routeName) {
            $this->actingInContext($cashier, $company, $branch)->get(route($routeName))->assertForbidden();
        }
    }

    public function test_cashier_can_read_immutable_movements_but_cannot_post_a_manual_movement_without_exception(): void
    {
        [$company, $branch, , , $cashier, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);

        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()->assertSee('Movimientos de efectivo')->assertSee('Saldo acumulado')
            ->assertDontSee('Registrar movimiento manual');
        $this->actingInContext($cashier, $company, $branch)->post(route('cash.movements.store'), [
            'type' => 'manual_in', 'amount' => '10.00', 'reason' => 'Cambio adicional',
        ])->assertForbidden();
        $this->assertDatabaseCount('cash_movements', 1);

        $this->expectException(LogicException::class);
        $session->movements()->firstOrFail()->update(['reason' => 'Alterado']);
    }

    public function test_explicit_user_permission_allows_manual_movement_only_inside_its_company(): void
    {
        [$company, $branch, , , $cashier, $register, $membership] = $this->context();
        $this->override($membership, Permission::RegisterManualCashMovements, true);
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);

        app(ManualCashMovementAction::class)->execute($session, CashMovementType::ManualIn, '25.00', 'Fondo de cambio', $cashier);
        $this->assertSame('125.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))->assertOk()->assertSee('Registrar movimiento manual');

        $otherCompany = Company::factory()->create();
        $this->assertFalse($cashier->canForCompany(Permission::RegisterManualCashMovements, $otherCompany));
    }

    public function test_cashier_cannot_withdraw_while_owner_and_admin_create_separate_audited_withdrawals(): void
    {
        [, , $owner, $admin, $cashier, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '300.00', $cashier);

        try {
            app(OwnerCashWithdrawalAction::class)->execute($session, '10.00', 'No autorizado', null, $cashier, 'cashier-withdrawal');
            $this->fail('La cajera no debe retirar efectivo del propietario.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('cash_movements', 1);
        }

        $adminMovement = app(OwnerCashWithdrawalAction::class)->execute($session, '50.00', 'Entrega parcial', 'Autoriza admin', $admin, 'admin-withdrawal');
        $ownerMovement = app(OwnerCashWithdrawalAction::class)->execute($session, '50.00', 'Entrega final', null, $owner, 'owner-withdrawal');
        $this->assertSame($admin->id, $adminMovement->authorized_by);
        $this->assertSame($owner->id, $ownerMovement->authorized_by);
        $this->assertSame('250.00', $adminMovement->resulting_balance_amount);
        $this->assertSame('200.00', $ownerMovement->resulting_balance_amount);
        $this->assertSame('200.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_closing_persists_balanced_short_and_over_without_altering_ledger_or_sales(): void
    {
        [, , $owner, , , $register] = $this->context();
        $shortSession = app(OpenCashSessionAction::class)->execute($register, '100.00', $owner);
        try {
            app(CloseCashSessionAction::class)->execute($shortSession, '90.00', $owner);
            $this->fail('Una diferencia requiere observación.');
        } catch (DomainException) {
            $this->assertNotNull($shortSession->fresh()->active_cash_register_id);
        }
        $short = app(CloseCashSessionAction::class)->execute($shortSession, '90.00', $owner, 'Faltan Bs 10');
        $this->assertSame('-10.00', $short->difference_amount);
        $this->assertSame(CashClosingBalanceStatus::Short, $short->closing_balance_status);
        $this->assertSame('Faltan Bs 10', $short->closing_observation);

        $balancedSession = app(OpenCashSessionAction::class)->execute($register, '90.00', $owner);
        $balanced = app(CloseCashSessionAction::class)->execute($balancedSession, '90.00', $owner);
        $this->assertSame('0.00', $balanced->difference_amount);
        $this->assertSame(CashClosingBalanceStatus::Balanced, $balanced->closing_balance_status);

        $overSession = app(OpenCashSessionAction::class)->execute($register, '90.00', $owner);
        $over = app(CloseCashSessionAction::class)->execute($overSession, '100.00', $owner, 'Sobran Bs 10');
        $this->assertSame('10.00', $over->difference_amount);
        $this->assertSame(CashClosingBalanceStatus::Over, $over->closing_balance_status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 3);
    }

    public function test_permission_ui_uses_restaurant_language_and_user_exceptions_still_protect_urls(): void
    {
        [$company, $branch, $owner, , $cashier, , $membership] = $this->context();
        $this->actingInContext($owner, $company, $branch)->get(route('memberships.edit', $membership->id))
            ->assertOk()->assertSee('Función en la empresa')->assertSee('Personalizar permisos')
            ->assertSee('Puede realizar cobros, trabajar con su turno de caja')
            ->assertSee('Usar perfil en todo el módulo')->assertSee('Permitir todo el módulo')
            ->assertSee('Bloquear todo el módulo')->assertSee('Perfil')->assertSee('Permitir')->assertSee('Bloquear')
            ->assertSee('data-advanced-permissions hidden', false)
            ->assertDontSee('Heredar del rol')->assertDontSee('Heredado: permitido')
            ->assertDontSee('Permitir explícitamente')->assertDontSee('Denegar explícitamente');

        $this->actingInContext($owner, $company, $branch)->put(route('memberships.update', $membership->id), [
            'name' => $cashier->name, 'role' => MembershipRole::Cashier->value, 'is_active' => '1',
            'permissions' => [Permission::ViewReports->value => 'allow', Permission::CancelOrders->value => 'deny'],
        ])->assertRedirect(route('memberships.index'));

        $this->assertTrue($cashier->canForCompany(Permission::ViewReports, $company));
        $this->assertFalse($cashier->canForCompany(Permission::CancelOrders, $company));
        $this->actingInContext($cashier, $company, $branch)->get(route('reports.sales'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('inventory.index'))->assertOk();
    }

    public function test_administrator_cannot_grant_a_permission_removed_from_their_own_membership_or_manage_an_owner(): void
    {
        [$company, $branch, $owner, $admin, $cashier, , $cashierMembership] = $this->context();
        $adminMembership = $admin->memberships()->where('company_id', $company->id)->firstOrFail();
        $ownerMembership = $owner->memberships()->where('company_id', $company->id)->firstOrFail();
        $this->override($adminMembership, Permission::ViewReports, false);

        $this->actingInContext($admin, $company, $branch)->put(route('memberships.update', $cashierMembership->id), [
            'name' => $cashier->name, 'role' => MembershipRole::Cashier->value, 'is_active' => '1',
            'permissions' => [Permission::ViewReports->value => 'allow'],
        ])->assertForbidden();
        $this->assertTrue($cashier->canForCompany(Permission::ViewReports, $company));
        $this->assertDatabaseMissing('membership_permission_overrides', [
            'membership_id' => $cashierMembership->id,
            'permission' => Permission::ViewReports->value,
        ]);
        $this->actingInContext($admin, $company, $branch)->get(route('memberships.edit', $ownerMembership->id))->assertForbidden();
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        [$owner] = $this->member($company, MembershipRole::Owner);
        [$admin] = $this->member($company, MembershipRole::Admin);
        [$cashier, $cashierMembership] = $this->member($company, MembershipRole::Cashier);
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja principal',
            'is_active' => true,
        ]);

        return [$company, $branch, $owner, $admin, $cashier, $register, $cashierMembership];
    }

    private function member(Company $company, MembershipRole $role): array
    {
        $user = User::factory()->create();
        $membership = Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return [$user, $membership];
    }

    private function override(Membership $membership, Permission $permission, bool $allowed): MembershipPermissionOverride
    {
        return MembershipPermissionOverride::query()->create([
            'company_id' => $membership->company_id,
            'membership_id' => $membership->id,
            'permission' => $permission,
            'allowed' => $allowed,
        ]);
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
