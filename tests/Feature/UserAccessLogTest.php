<?php

namespace Tests\Feature;

use App\Actions\OpenCashSessionAction;
use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserAccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserAccessLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_login_creates_an_access_log_for_the_laravel_session(): void
    {
        [$company, $branch, $cashier] = $this->operationalUser(MembershipRole::Cashier);

        $this->post(route('login.store'), ['email' => $cashier->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($cashier);
        $log = UserAccessLog::query()->sole();
        $this->assertSame($company->id, $log->company_id);
        $this->assertSame($branch->id, $log->branch_id);
        $this->assertSame($cashier->id, $log->user_id);
        $this->assertSame(MembershipRole::Cashier, $log->role);
        $this->assertNull($log->logged_out_at);
        $this->assertNotNull($log->logged_in_at);
        $this->assertNotNull($log->last_activity_at);
        $this->assertSame(64, strlen($log->session_hash));
    }

    public function test_kitchen_login_creates_an_access_log(): void
    {
        [$company, $branch, $kitchen] = $this->operationalUser(MembershipRole::Kitchen);

        $this->post(route('login.store'), ['email' => $kitchen->email, 'password' => 'password']);

        $this->assertDatabaseHas('user_access_logs', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $kitchen->id,
            'role' => MembershipRole::Kitchen->value,
            'logged_out_at' => null,
        ]);
    }

    public function test_successful_cash_close_records_reason_and_logs_cashier_out(): void
    {
        [$company, $branch, $cashier] = $this->operationalUser(MembershipRole::Cashier);
        $register = $this->cashRegister($company, $branch);
        $this->post(route('login.store'), ['email' => $cashier->email, 'password' => 'password']);
        $cashSession = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);

        $this->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('cash.close'), ['counted_cash_amount' => '100.00'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('cash_sessions', ['id' => $cashSession->id, 'status' => 'closed']);
        $this->assertDatabaseHas('user_access_logs', [
            'company_id' => $company->id,
            'user_id' => $cashier->id,
            'logout_reason' => 'cash_closed',
        ]);
        $this->assertNotNull(UserAccessLog::query()->firstOrFail()->logged_out_at);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_failed_cash_close_does_not_log_cashier_out_or_close_access_log(): void
    {
        [$company, $branch, $cashier] = $this->operationalUser(MembershipRole::Cashier);
        $register = $this->cashRegister($company, $branch);
        $this->post(route('login.store'), ['email' => $cashier->email, 'password' => 'password']);
        $cashSession = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);

        $this->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->from(route('cash.current'))
            ->post(route('cash.close'), ['counted_cash_amount' => '90.00'])
            ->assertRedirect(route('cash.current'))
            ->assertSessionHasErrors('cash');

        $this->assertAuthenticatedAs($cashier);
        $this->assertDatabaseHas('cash_sessions', ['id' => $cashSession->id, 'status' => 'open']);
        $this->assertDatabaseHas('user_access_logs', [
            'company_id' => $company->id,
            'user_id' => $cashier->id,
            'logged_out_at' => null,
            'logout_reason' => null,
        ]);
    }

    public function test_manual_logout_records_manual_reason(): void
    {
        [, , $kitchen] = $this->operationalUser(MembershipRole::Kitchen);
        $this->post(route('login.store'), ['email' => $kitchen->email, 'password' => 'password']);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('user_access_logs', ['user_id' => $kitchen->id, 'logout_reason' => 'manual']);
        $this->assertNotNull(UserAccessLog::query()->firstOrFail()->logged_out_at);
    }

    public function test_owner_cash_close_keeps_owner_authenticated_and_creates_no_access_log(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $cashSession = app(OpenCashSessionAction::class)->execute($this->cashRegister($company, $branch), '25.00', $owner);

        $this->actingInContext($owner, $company, $branch)
            ->post(route('cash.close'), ['counted_cash_amount' => '25.00'])
            ->assertRedirect(route('cash.index'));

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('cash_sessions', ['id' => $cashSession->id, 'status' => 'closed']);
        $this->assertDatabaseCount('user_access_logs', 0);
    }

    public function test_owner_and_admin_can_view_history_but_operational_roles_receive_403(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $cashier = User::factory()->create(['name' => 'Caja Histórica']);
        Membership::factory()->for($company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $this->accessLog($company, $branch, $cashier, MembershipRole::Cashier);

        foreach ([MembershipRole::Owner, MembershipRole::Admin] as $role) {
            $viewer = User::factory()->create();
            Membership::factory()->for($company)->for($viewer)->create(['role' => $role]);
            $this->actingInContext($viewer, $company, $branch)
                ->get(route('user-access-logs.index'))
                ->assertOk()
                ->assertSee('Caja Histórica');
        }

        foreach ([MembershipRole::Cashier, MembershipRole::Kitchen] as $role) {
            $viewer = User::factory()->create();
            Membership::factory()->for($company)->for($viewer)->create(['role' => $role]);
            $this->actingInContext($viewer, $company, $branch)
                ->get(route('user-access-logs.index'))
                ->assertForbidden();
        }
    }

    public function test_history_is_isolated_by_company(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $visible = User::factory()->create(['name' => 'Usuario Visible']);
        $this->accessLog($company, $branch, $visible, MembershipRole::Kitchen);

        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $hidden = User::factory()->create(['name' => 'Usuario Oculto']);
        $this->accessLog($otherCompany, $otherBranch, $hidden, MembershipRole::Cashier);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('user-access-logs.index'))
            ->assertOk()
            ->assertSee('Usuario Visible')
            ->assertDontSee('Usuario Oculto');
    }

    public function test_last_activity_is_throttled_to_three_minutes(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        [$company, $branch, $cashier] = $this->operationalUser(MembershipRole::Cashier);
        $this->post(route('login.store'), ['email' => $cashier->email, 'password' => 'password']);
        $original = UserAccessLog::query()->firstOrFail()->last_activity_at;

        Carbon::setTestNow('2026-09-16 12:02:00');
        $this->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('cash.index'))
            ->assertOk();
        $this->assertTrue(UserAccessLog::query()->firstOrFail()->last_activity_at->equalTo($original));

        Carbon::setTestNow('2026-09-16 12:04:00');
        $this->get(route('cash.index'))->assertOk();
        $this->assertTrue(UserAccessLog::query()->firstOrFail()->last_activity_at->equalTo(now()));
        Carbon::setTestNow();
    }

    private function operationalUser(MembershipRole $role): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return [$company, $branch, $user];
    }

    private function cashRegister(Company $company, Branch $branch): CashRegister
    {
        return CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);
    }

    private function accessLog(Company $company, Branch $branch, User $user, MembershipRole $role): UserAccessLog
    {
        return UserAccessLog::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'role' => $role,
            'session_hash' => hash('sha256', $company->id.'-'.$user->id),
            'logged_in_at' => now(),
            'last_activity_at' => now(),
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
