<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Enums\PermissionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\MembershipPermissionOverride;
use App\Models\User;
use App\Services\MembershipPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipPermissionsUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_role_inheritance_and_operational_profiles_remain_exact(): void
    {
        $company = Company::factory()->create();

        foreach ([MembershipRole::Cashier, MembershipRole::Waiter, MembershipRole::Kitchen] as $role) {
            [, $membership] = $this->member($company, $role);

            $this->assertDatabaseMissing('membership_permission_overrides', ['membership_id' => $membership->id]);
            foreach (Permission::cases() as $permission) {
                $this->assertSame($role->allows($permission), $membership->allows($permission), $role->value.' / '.$permission->value);
            }
        }

        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ManageMemberships));
        $this->assertFalse(MembershipRole::Waiter->allows(Permission::ViewCash));
        $this->assertFalse(MembershipRole::Waiter->allows(Permission::CreatePayments));
        $this->assertTrue(MembershipRole::Kitchen->allows(Permission::ManageKitchen));
        $this->assertFalse(MembershipRole::Kitchen->allows(Permission::ViewCash));
        $this->assertFalse(MembershipRole::Kitchen->allows(Permission::ViewReports));
        $this->assertFalse(MembershipRole::Kitchen->allows(Permission::ViewMemberships));
    }

    public function test_allow_block_and_restore_to_profile_keep_the_existing_override_semantics(): void
    {
        [$company, $branch, $owner] = $this->context();
        [$cashier, $membership] = $this->member($company, MembershipRole::Cashier);

        $this->update($owner, $company, $branch, $membership, $cashier, MembershipRole::Cashier, [
            Permission::ViewReports->value => 'allow',
            Permission::CancelOrders->value => 'deny',
        ])->assertRedirect(route('memberships.index'));

        $this->assertTrue($membership->fresh()->allows(Permission::ViewReports));
        $this->assertFalse($membership->fresh()->allows(Permission::CancelOrders));
        $this->assertDatabaseHas('membership_permission_overrides', [
            'membership_id' => $membership->id,
            'permission' => Permission::ViewReports->value,
            'allowed' => true,
        ]);

        $this->update($owner, $company, $branch, $membership, $cashier, MembershipRole::Cashier, [
            Permission::ViewReports->value => 'inherit',
            Permission::CancelOrders->value => 'deny',
        ])->assertRedirect(route('memberships.index'));

        $this->assertDatabaseMissing('membership_permission_overrides', [
            'membership_id' => $membership->id,
            'permission' => Permission::ViewReports->value,
        ]);
        $this->assertDatabaseHas('membership_permission_overrides', [
            'membership_id' => $membership->id,
            'permission' => Permission::CancelOrders->value,
            'allowed' => false,
        ]);
    }

    public function test_role_change_preserves_submitted_existing_exceptions_and_refreshes_inheritance(): void
    {
        [$company, $branch, $owner] = $this->context();
        [$cashier, $membership] = $this->member($company, MembershipRole::Cashier);
        $this->override($membership, Permission::ViewReports, true);
        $this->override($membership, Permission::CancelOrders, false);

        $this->actingInContext($owner, $company, $branch)->get(route('memberships.edit', $membership->id))
            ->assertOk();
        $this->assertDatabaseCount('membership_permission_overrides', 2);

        $this->update($owner, $company, $branch, $membership, $cashier, MembershipRole::Waiter, [
            Permission::ViewReports->value => 'allow',
            Permission::CancelOrders->value => 'deny',
        ])->assertRedirect(route('memberships.index'));

        $membership = $membership->fresh();
        $this->assertSame(MembershipRole::Waiter, $membership->role);
        $this->assertTrue($membership->allows(Permission::ViewReports));
        $this->assertFalse($membership->allows(Permission::CancelOrders));
        $this->assertCount(2, $membership->permissionOverrides()->get());
    }

    public function test_visual_catalog_groups_each_exposed_permission_once_without_becoming_authorization(): void
    {
        $groups = app(MembershipPermissionService::class)->groupedCatalog();
        $permissions = collect($groups)->flatMap(fn (array $group): array => $group['permissions']);
        $expected = collect(Permission::cases());

        $this->assertSame(PermissionModule::cases(), collect($groups)->pluck('key')
            ->map(fn (string $key): PermissionModule => PermissionModule::from($key))->all());
        $this->assertCount($expected->count(), $permissions);
        $this->assertCount($expected->count(), $permissions->unique(fn (Permission $permission): string => $permission->value));
        $this->assertEqualsCanonicalizing($expected->map->value->values()->all(), $permissions->map->value->values()->all());
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::CreatePayments));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ManageMemberships));
    }

    public function test_membership_from_another_company_cannot_be_edited_through_the_active_company(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherCompany = Company::factory()->create();
        [, $foreignMembership] = $this->member($otherCompany, MembershipRole::Cashier);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('memberships.edit', $foreignMembership->id))
            ->assertNotFound();
        $this->actingInContext($owner, $company, $branch)
            ->put(route('memberships.update', $foreignMembership->id), [
                'name' => 'No debe cambiar',
                'role' => MembershipRole::Admin->value,
                'is_active' => '1',
            ])->assertNotFound();

        $this->assertSame(MembershipRole::Cashier, $foreignMembership->fresh()->role);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        [$owner] = $this->member($company, MembershipRole::Owner);

        return [$company, $branch, $owner];
    }

    private function member(Company $company, MembershipRole $role): array
    {
        $user = User::factory()->create();
        $membership = Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return [$user, $membership];
    }

    private function override(Membership $membership, Permission $permission, bool $allowed): void
    {
        MembershipPermissionOverride::query()->create([
            'company_id' => $membership->company_id,
            'membership_id' => $membership->id,
            'permission' => $permission,
            'allowed' => $allowed,
        ]);
    }

    private function update(User $owner, Company $company, Branch $branch, Membership $membership, User $user, MembershipRole $role, array $permissions)
    {
        return $this->actingInContext($owner, $company, $branch)->put(route('memberships.update', $membership->id), [
            'name' => $user->name,
            'role' => $role->value,
            'is_active' => '1',
            'permissions' => $permissions,
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
