<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OnlineSystemIdentityService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnlineSystemIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_human_owner_admin_and_cashier_can_log_in_through_interactive_provider(): void
    {
        $company = Company::factory()->create();
        foreach (['owner', 'admin', 'cashier'] as $role) {
            $user = User::factory()->create(['password' => Hash::make('human-test-password')]);
            Membership::factory()->for($company)->for($user)->create(['role' => $role]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'human-test-password'])
                ->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_disabled_feature_cannot_create_a_system_identity(): void
    {
        config()->set('storefront.online_dynamic_qr_enabled', false);
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        try {
            app(OnlineSystemIdentityService::class)->forBranch($company, $branch);
            $this->fail('La identidad no debe crearse con la bandera desactivada.');
        } catch (DomainException) {
            $this->assertDatabaseCount('online_system_identities', 0);
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_identity_is_persistent_scoped_and_has_no_employee_membership(): void
    {
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $otherBranch = Branch::factory()->for($company)->create();
        $service = app(OnlineSystemIdentityService::class);
        $identity = $service->forBranch($company, $branch);
        $this->assertSame('Sistema — Tienda online', $identity->name);
        $this->assertSame($identity->id, $service->forBranch($company, $branch)->id);
        $this->assertNotSame($identity->id, $service->forBranch($company, $otherBranch)->id);
        $this->assertDatabaseHas('online_system_identities', ['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $identity->id]);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertFalse($identity->canForCompany(Permission::CreatePayments, $company));
        try {
            app(CompanyAccessService::class)->ensure($identity, $company, Permission::ManageOrders);
            $this->fail('Una identidad interna no debe adquirir permisos de empleado.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $other = Company::factory()->create();
        try {
            $service->forBranch($other, $branch);
            $this->fail('La identidad no debe vincular una sucursal de otra empresa.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('online_system_identities', 2);
        }
    }

    public function test_system_identity_is_excluded_from_password_and_session_authentication(): void
    {
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $identity = app(OnlineSystemIdentityService::class)->forBranch($company, $branch);
        $this->assertSame('!non-interactive-system-identity!', $identity->getRawOriginal('password'));
        // Even if a credential is accidentally set later, the interactive provider must reject it.
        DB::table('users')->where('id', $identity->id)->update(['password' => Hash::make('password-for-test'), 'remember_token' => 'test-token']);
        $provider = Auth::guard('web')->getProvider();
        $this->assertNull($provider->retrieveByCredentials(['email' => $identity->email, 'password' => 'password-for-test']));
        $this->assertNull($provider->retrieveById($identity->id));
        $this->assertNull($provider->retrieveByToken($identity->id, 'test-token'));
        $this->post(route('login.store'), ['email' => $identity->email, 'password' => 'password-for-test'])->assertSessionHasErrors('email');
        $this->assertGuest();
        try {
            app(OnlineSystemIdentityService::class)->forBranch($company, $branch);
            $this->fail('Una identidad interna con credenciales alteradas debe bloquearse.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        $human = User::factory()->create(['password' => Hash::make('human-test-password')]);
        $this->assertSame($human->id, $provider->retrieveByCredentials(['email' => $human->email, 'password' => 'human-test-password'])->getAuthIdentifier());
    }
}
