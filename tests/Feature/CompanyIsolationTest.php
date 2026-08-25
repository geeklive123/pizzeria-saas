<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_queries_can_be_explicitly_limited_to_the_users_memberships(): void
    {
        $user = User::factory()->create();
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        Membership::factory()->for($ownCompany)->for($user)->owner()->create();

        $companies = Company::query()->forUser($user)->get();

        $this->assertTrue($companies->contains($ownCompany));
        $this->assertFalse($companies->contains($otherCompany));
    }

    public function test_company_context_rejects_a_company_without_an_active_membership(): void
    {
        $user = User::factory()->create();
        $otherCompany = Company::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(CompanyContext::class)->setForUser($user, $otherCompany);
    }

    public function test_user_cannot_view_a_branch_from_another_company(): void
    {
        $user = User::factory()->create();
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        Membership::factory()->for($ownCompany)->for($user)->owner()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();

        $this->assertFalse(Gate::forUser($user)->allows('view', $otherBranch));
    }

    public function test_owner_has_management_access_inside_their_company(): void
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $company));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $branch));
    }

    public function test_last_active_owner_cannot_be_deactivated(): void
    {
        $membership = Membership::factory()->owner()->create();

        $this->expectException(\DomainException::class);

        $membership->update(['is_active' => false]);
    }

    public function test_last_active_owner_membership_cannot_be_deleted(): void
    {
        $membership = Membership::factory()->owner()->create();

        $this->expectException(\DomainException::class);

        $membership->delete();
    }

    public function test_user_account_cannot_be_deleted_when_it_is_the_last_active_owner(): void
    {
        $membership = Membership::factory()->owner()->create();

        $this->expectException(\DomainException::class);

        $membership->user->delete();
    }

    public function test_owner_can_be_deactivated_when_another_active_owner_exists(): void
    {
        $company = Company::factory()->create();
        $firstOwner = Membership::factory()->for($company)->owner()->create();
        Membership::factory()->for($company)->owner()->create();

        $firstOwner->update(['is_active' => false]);

        $this->assertFalse($firstOwner->refresh()->is_active);
        $this->assertDatabaseHas('memberships', [
            'company_id' => $company->getKey(),
            'role' => MembershipRole::Owner->value,
            'is_active' => true,
        ]);
    }
}
