<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaidOrderHistoryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'America/La_Paz'));
    }

    public function test_cashier_sees_only_today_and_cannot_force_historical_filters(): void
    {
        [$company, $branch] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier);
        $today = $this->paidOrder($company, $branch, $cashier, 'Hoy cajera', '2026-09-06 00:15:00');
        $yesterday = $this->paidOrder($company, $branch, $cashier, 'Ayer cajera', '2026-09-05 23:45:00');

        $this->actingInContext($cashier, $company, $branch)
            ->get(route('orders.index', ['preset' => 'yesterday', 'date_from' => '2020-01-01', 'date_to' => '2030-01-01']))
            ->assertOk()
            ->assertSee($this->historicalNumber($today))
            ->assertDontSee($this->historicalNumber($yesterday))
            ->assertSee('Venta anterior')
            ->assertSee('Mostrando pedidos pagados de hoy')
            ->assertDontSee('Rango personalizado');

        $this->actingInContext($cashier, $company, $branch)
            ->get(route('orders.show', $yesterday->ulid))
            ->assertForbidden();
    }

    public function test_waiter_sees_only_today_paid_orders(): void
    {
        [$company, $branch] = $this->context();
        $waiter = $this->member($company, MembershipRole::Waiter);
        $today = $this->paidOrder($company, $branch, $waiter, 'Hoy mesero', '2026-09-06 23:45:00');
        $yesterday = $this->paidOrder($company, $branch, $waiter, 'Ayer mesero', '2026-09-05 12:00:00');

        $this->actingInContext($waiter, $company, $branch)
            ->get(route('orders.index', ['preset' => 'month']))
            ->assertOk()
            ->assertSee($this->historicalNumber($today))
            ->assertDontSee($this->historicalNumber($yesterday))
            ->assertSee('Venta anterior')
            ->assertSee('Puedes consultar y reimprimir los pedidos pagados del día actual.');
    }

    public function test_owner_can_filter_today_yesterday_and_week(): void
    {
        [$company, $branch] = $this->context();
        $owner = $this->member($company, MembershipRole::Owner);
        $today = $this->paidOrder($company, $branch, $owner, 'Hoy owner', '2026-09-06 10:00:00');
        $yesterday = $this->paidOrder($company, $branch, $owner, 'Ayer owner', '2026-09-05 10:00:00');
        $week = $this->paidOrder($company, $branch, $owner, 'Semana owner', '2026-09-01 10:00:00');
        $older = $this->paidOrder($company, $branch, $owner, 'Anterior owner', '2026-08-30 10:00:00');

        $this->actingInContext($owner, $company, $branch)->get(route('orders.index', ['preset' => 'today']))
            ->assertOk()->assertSee($this->historicalNumber($today))->assertDontSee($this->historicalNumber($yesterday))->assertSee('Venta anterior');
        $this->actingInContext($owner, $company, $branch)->get(route('orders.index', ['preset' => 'yesterday']))
            ->assertOk()->assertSee($this->historicalNumber($yesterday))->assertDontSee($this->historicalNumber($today))->assertSee('Venta anterior');
        $this->actingInContext($owner, $company, $branch)->get(route('orders.index', ['preset' => 'week']))
            ->assertOk()->assertSee($this->historicalNumber($today))->assertSee($this->historicalNumber($yesterday))
            ->assertSee($this->historicalNumber($week))->assertDontSee($this->historicalNumber($older))->assertSee('Venta anterior')
            ->assertSee('Rango personalizado')->assertSee('Creado por');
    }

    public function test_admin_can_use_a_valid_custom_range(): void
    {
        [$company, $branch] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);
        $included = $this->paidOrder($company, $branch, $admin, 'Incluido', '2026-08-15 12:00:00');
        $excluded = $this->paidOrder($company, $branch, $admin, 'Excluido', '2026-08-20 12:00:00');

        $this->actingInContext($admin, $company, $branch)->get(route('orders.index', [
            'preset' => 'custom', 'date_from' => '2026-08-10', 'date_to' => '2026-08-16',
        ]))->assertOk()->assertSee($this->historicalNumber($included))->assertDontSee($this->historicalNumber($excluded))->assertSee('Venta anterior');

        $this->actingInContext($admin, $company, $branch)->get(route('orders.index', [
            'preset' => 'custom', 'date_from' => '2026-08-20', 'date_to' => '2026-08-10',
        ]))->assertSessionHasErrors('date_to');
    }

    public function test_owner_pagination_keeps_the_selected_filter(): void
    {
        [$company, $branch] = $this->context();
        $owner = $this->member($company, MembershipRole::Owner);
        foreach (range(1, 51) as $index) {
            $this->paidOrder($company, $branch, $owner, 'Pedido '.$index, '2026-09-05 12:00:00');
        }

        $response = $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.index', ['preset' => 'yesterday']))
            ->assertOk();
        $nextPage = $response->viewData('paidOrders')->nextPageUrl();

        $this->assertStringContainsString('paid_page=2', $nextPage);
        $this->assertStringContainsString('preset=yesterday', $nextPage);
    }

    public function test_historical_reprints_are_blocked_only_for_operational_history_roles(): void
    {
        [$company, $branch] = $this->context();
        $owner = $this->member($company, MembershipRole::Owner);
        $cashier = $this->member($company, MembershipRole::Cashier);
        $waiter = $this->member($company, MembershipRole::Waiter);
        $today = $this->paidOrder($company, $branch, $owner, 'Hoy', '2026-09-06 10:00:00');
        $yesterday = $this->paidOrder($company, $branch, $owner, 'Ayer', '2026-09-05 10:00:00');

        foreach ([$cashier, $waiter] as $user) {
            $this->actingInContext($user, $company, $branch)
                ->post(route('orders.reprint.kitchen', $yesterday->ulid))->assertForbidden();
        }
        $this->actingInContext($cashier, $company, $branch)
            ->post(route('orders.reprint.ticket', $yesterday->ulid))->assertForbidden();
        $this->actingInContext($cashier, $company, $branch)
            ->post(route('orders.reprint.kitchen', $today->ulid))->assertRedirect();
        $this->actingInContext($owner, $company, $branch)
            ->post(route('orders.reprint.kitchen', $yesterday->ulid))->assertRedirect();
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

    private function paidOrder(Company $company, Branch $branch, User $user, string $customer, string $closedAt): Order
    {
        return Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'status' => OrderStatus::Paid,
            'customer_name' => $customer,
            'closed_at' => CarbonImmutable::parse($closedAt, 'America/La_Paz')->utc(),
            'created_by' => $user->id,
        ]);
    }

    private function historicalNumber(Order $order): string
    {
        return '#'.str_pad((string) $order->order_number, 6, '0', STR_PAD_LEFT);
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
