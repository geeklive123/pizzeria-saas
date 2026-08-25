<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProvisionProductionOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ENVIRONMENT_KEYS = [
        'PROVISION_OWNER_NAME',
        'PROVISION_OWNER_EMAIL',
        'PROVISION_OWNER_COMPANY',
        'PROVISION_OWNER_BRANCH',
        'PROVISION_OWNER_PASSWORD',
        'PROVISION_OWNER_PASSWORD_CONFIRMATION',
    ];

    protected function tearDown(): void
    {
        foreach (self::ENVIRONMENT_KEYS as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function test_interactive_command_creates_and_authenticates_a_full_access_owner(): void
    {
        $password = $this->secureTestPassword();

        $this->artisan('app:provision-owner')
            ->expectsQuestion('Nombre del owner', 'Propietaria Real')
            ->expectsQuestion('Correo del owner', 'OWNER.REAL@EXAMPLE.COM')
            ->expectsQuestion('Nombre de la empresa', 'Pizzería Producción')
            ->expectsQuestion('Nombre de la sucursal principal', 'Sucursal Central')
            ->expectsQuestion('Contraseña del owner', $password)
            ->expectsQuestion('Confirmar contraseña', $password)
            ->assertSuccessful();

        $company = Company::query()->where('name', 'Pizzería Producción')->firstOrFail();
        $user = User::query()->where('email', 'owner.real@example.com')->firstOrFail();
        $membership = Membership::query()->whereBelongsTo($company)->whereBelongsTo($user)->firstOrFail();

        $this->assertSame(MembershipRole::Owner, $membership->role);
        $this->assertTrue($membership->is_active);
        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => $password]));
        $this->assertTrue(collect(Permission::cases())->every(
            fn (Permission $permission): bool => $user->canForCompany($permission, $company),
        ));
        $this->assertDatabaseHas('branches', [
            'company_id' => $company->id,
            'name' => 'Sucursal Central',
            'is_active' => true,
        ]);
    }

    public function test_environment_mode_is_idempotent_and_does_not_duplicate_records(): void
    {
        $this->setProvisionEnvironment();

        $this->artisan('app:provision-owner', ['--no-interaction' => true])->assertSuccessful();
        $this->artisan('app:provision-owner', ['--no-interaction' => true])->assertSuccessful();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_existing_owner_requires_explicit_permission_and_owner_demo_is_unchanged(): void
    {
        $company = Company::factory()->create(['name' => 'Mi Pizzería']);
        Branch::factory()->for($company)->create(['name' => 'Principal']);
        $demo = User::factory()->create(['name' => 'Owner Demo', 'email' => 'owner@pizzeria.test']);
        $demoMembership = Membership::factory()->for($company)->for($demo)->owner()->create();
        $originalPasswordHash = $demo->password;
        $this->setProvisionEnvironment(company: 'Mi Pizzería', branch: 'Principal');

        $this->artisan('app:provision-owner', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('memberships', 1);

        $this->artisan('app:provision-owner', [
            '--no-interaction' => true,
            '--allow-existing-owner' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('memberships', 2);
        $this->assertSame('Owner Demo', $demo->refresh()->name);
        $this->assertSame($originalPasswordHash, $demo->password);
        $this->assertTrue($demoMembership->refresh()->isActiveOwner());
    }

    public function test_existing_email_requires_its_current_password_and_is_not_modified_on_failure(): void
    {
        $company = Company::factory()->create(['name' => 'Pizzería Producción']);
        $existingPassword = $this->secureTestPassword();
        $existing = User::factory()->create([
            'email' => 'production.owner@example.com',
            'password' => Hash::make($existingPassword),
        ]);
        $originalHash = $existing->password;
        $this->setProvisionEnvironment();

        $this->artisan('app:provision-owner', ['--no-interaction' => true])->assertFailed();

        $this->assertSame($originalHash, $existing->refresh()->password);
        $this->assertDatabaseMissing('memberships', [
            'company_id' => $company->id,
            'user_id' => $existing->id,
        ]);
        $this->assertDatabaseCount('branches', 0);
    }

    private function setProvisionEnvironment(
        string $company = 'Pizzería Producción',
        string $branch = 'Sucursal Central',
    ): void {
        $password = $this->secureTestPassword();
        $values = [
            'PROVISION_OWNER_NAME' => 'Propietaria Real',
            'PROVISION_OWNER_EMAIL' => 'production.owner@example.com',
            'PROVISION_OWNER_COMPANY' => $company,
            'PROVISION_OWNER_BRANCH' => $branch,
            'PROVISION_OWNER_PASSWORD' => $password,
            'PROVISION_OWNER_PASSWORD_CONFIRMATION' => $password,
        ];

        foreach ($values as $key => $value) {
            putenv($key.'='.$value);
        }
    }

    private function secureTestPassword(): string
    {
        return 'Aa1!'.Str::random(20);
    }
}
