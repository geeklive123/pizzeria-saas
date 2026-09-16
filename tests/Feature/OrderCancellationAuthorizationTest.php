<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancellationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_the_correct_cancel_action_and_can_cancel_the_order(): void
    {
        [$company, $branch] = $this->context();
        $owner = $this->member($company, MembershipRole::Owner);
        $order = $this->order($company, $branch, $owner, 123);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Anular')
            ->assertSee('data-cancel-order-url="'.route('orders.cancel', $order->ulid).'"', false)
            ->assertSee('data-cancel-order-number="#000123"', false);

        $this->actingInContext($owner, $company, $branch)
            ->post(route('orders.cancel', $order->ulid), ['reason' => 'Pedido duplicado'])
            ->assertRedirect(route('orders.index'));

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_admin_sees_the_cancel_action_and_can_cancel_the_order(): void
    {
        [$company, $branch] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);
        $order = $this->order($company, $branch, $admin, 124);

        $this->actingInContext($admin, $company, $branch)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Anular')
            ->assertSee(route('orders.cancel', $order->ulid), false);

        $this->actingInContext($admin, $company, $branch)
            ->post(route('orders.cancel', $order->ulid), ['reason' => 'Error de captura'])
            ->assertRedirect(route('orders.index'));

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_cashier_cannot_see_or_execute_order_cancellation(): void
    {
        $this->assertRoleCannotCancel(MembershipRole::Cashier, canViewOrders: true);
    }

    public function test_waiter_cannot_see_or_execute_order_cancellation(): void
    {
        $this->assertRoleCannotCancel(MembershipRole::Waiter, canViewOrders: true);
    }

    public function test_kitchen_cannot_see_or_execute_order_cancellation(): void
    {
        $this->assertRoleCannotCancel(MembershipRole::Kitchen, canViewOrders: false);
    }

    private function assertRoleCannotCancel(MembershipRole $role, bool $canViewOrders): void
    {
        [$company, $branch] = $this->context();
        $user = $this->member($company, $role);
        $order = $this->order($company, $branch, $user, fake()->unique()->numberBetween(200, 999));

        $listing = $this->actingInContext($user, $company, $branch)->get(route('orders.index'));
        $canViewOrders ? $listing->assertOk()->assertDontSee('Anular') : $listing->assertForbidden()->assertDontSee('Anular');

        $detail = $this->actingInContext($user, $company, $branch)->get(route('orders.show', $order->ulid));
        $canViewOrders ? $detail->assertOk()->assertDontSee('Anular') : $detail->assertForbidden()->assertDontSee('Anular');

        $this->actingInContext($user, $company, $branch)
            ->post(route('orders.cancel', $order->ulid), ['reason' => 'Intento no autorizado'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Open, $order->refresh()->status);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();

        return [$company, $branch];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function order(Company $company, Branch $branch, User $user, int $number): Order
    {
        return Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'order_number' => $number,
            'operational_number' => $number,
            'created_by' => $user->id,
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
