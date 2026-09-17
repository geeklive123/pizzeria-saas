<?php

namespace Tests\Feature;

use App\Actions\RestoreLegacyCancelledPaidOrderAction;
use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderCancellationAudit;
use App\Models\User;
use App\Services\LegacyRestoreEnvironmentGuard;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Tests\TestCase;

class LegacyRestoreProductionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_without_allow_production_is_rejected_before_database_access(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('orders:restore-legacy-cancelled-paid', [
            'operational_number' => 156,
            '--company' => 1,
            '--branch' => 1,
            '--actor-email' => 'owner@example.com',
            '--execute' => true,
        ])->assertFailed()->expectsOutputToContain('--allow-production');
    }

    public function test_production_allow_without_execute_only_runs_dry_run_and_prints_summary(): void
    {
        $fixture = $this->commandFixture('production');
        $report = $this->report($fixture['order']);
        $action = Mockery::mock(RestoreLegacyCancelledPaidOrderAction::class);
        $action->shouldReceive('dryRun')->once()->withArgs(fn (Order $order, User $actor, array $expectations): bool => $order->is($fixture['order']) && $actor->is($fixture['actor']) && $expectations['order.id'] === 210
        )->andReturn($report);
        $action->shouldNotReceive('execute');
        $this->app->instance(RestoreLegacyCancelledPaidOrderAction::class, $action);

        $this->commandArguments(execute: false)
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('210 / #156')
            ->expectsOutputToContain('152 / 157')
            ->expectsOutputToContain($fixture['actor']->email);

        $this->assertSame(OrderStatus::Cancelled, $fixture['order']->refresh()->status);
        $this->assertDatabaseCount('order_cancellation_audits', 0);
    }

    public function test_production_with_both_flags_can_execute_only_after_valid_dry_run(): void
    {
        $fixture = $this->commandFixture('production');
        $report = $this->report($fixture['order']);
        $audit = new OrderCancellationAudit;
        $audit->id = 900;
        $audit->ulid = '01m2productionlegacyrestore';
        $audit->setRelation('children', collect([new OrderCancellationAudit]));
        $action = Mockery::mock(RestoreLegacyCancelledPaidOrderAction::class);
        $action->shouldReceive('dryRun')->once()->andReturn($report);
        $action->shouldReceive('execute')->once()->ordered()->andReturn($audit);
        $this->app->instance(RestoreLegacyCancelledPaidOrderAction::class, $action);

        $this->commandArguments(execute: true)
            ->assertSuccessful()
            ->expectsOutputToContain('OPERACION CON ESCRITURAS')
            ->expectsOutputToContain('RESTAURAR VENTA LEGACY')
            ->expectsOutputToContain('completada en production');
    }

    public function test_second_execution_and_changed_evidence_are_rejected_without_execute(): void
    {
        $fixture = $this->commandFixture('production');
        $action = Mockery::mock(RestoreLegacyCancelledPaidOrderAction::class);
        $action->shouldReceive('dryRun')->once()->andThrow(new DomainException('La venta ya posee una auditoria PaidOrder.'));
        $action->shouldNotReceive('execute');
        $this->app->instance(RestoreLegacyCancelledPaidOrderAction::class, $action);

        $this->commandArguments(execute: true)
            ->assertFailed()
            ->expectsOutputToContain('auditoria PaidOrder');
        $this->assertSame(OrderStatus::Cancelled, $fixture['order']->refresh()->status);

        $this->bindGuard('production');
        $changedAction = Mockery::mock(RestoreLegacyCancelledPaidOrderAction::class);
        $changedAction->shouldReceive('dryRun')->once()
            ->andThrow(new DomainException('Legacy evidence does not match the expected manifest at payments.0.amount.'));
        $changedAction->shouldNotReceive('execute');
        $this->app->instance(RestoreLegacyCancelledPaidOrderAction::class, $changedAction);

        $this->commandArguments(execute: true)
            ->assertFailed()
            ->expectsOutputToContain('expected manifest');
        $this->assertSame(OrderStatus::Cancelled, $fixture['order']->refresh()->status);
    }

    public function test_local_flow_remains_available_without_allow_production(): void
    {
        $fixture = $this->commandFixture('local');
        $action = Mockery::mock(RestoreLegacyCancelledPaidOrderAction::class);
        $action->shouldReceive('dryRun')->once()->andReturn($this->report($fixture['order']));
        $action->shouldNotReceive('execute');
        $this->app->instance(RestoreLegacyCancelledPaidOrderAction::class, $action);

        $this->artisan('orders:restore-legacy-cancelled-paid', [
            'operational_number' => 156,
            '--company' => 1,
            '--branch' => 1,
            '--actor-email' => $fixture['actor']->email,
        ])->assertSuccessful()->expectsOutputToContain('DRY-RUN');

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.database', 'pizzeria_saas');
        config()->set('database.connections.mysql.host', '127.0.0.1');
        $this->assertSame('local', (new LegacyRestoreEnvironmentGuard)->assertAllowed(false));
        config()->set('database.default', 'sqlite');
    }

    public function test_environment_guard_validates_expected_production_database_without_connecting(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.database', 'production_database');
        config()->set('database.connections.mysql.host', 'production-host');
        config()->set('legacy_restore.production_database', 'different_database');
        config()->set('legacy_restore.production_host', 'production-host');

        try {
            app(LegacyRestoreEnvironmentGuard::class)->assertAllowed(true);
            $this->fail('A database mismatch had to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different_database', $exception->getMessage());
        }

        config()->set('legacy_restore.production_database', 'production_database');
        config()->set('legacy_restore.production_host', 'different-host');
        try {
            app(LegacyRestoreEnvironmentGuard::class)->assertAllowed(true);
            $this->fail('A host mismatch had to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('HOST', $exception->getMessage());
        }

        config()->set('legacy_restore.production_host', 'production-host');
        $this->assertSame('production', app(LegacyRestoreEnvironmentGuard::class)->assertAllowed(true));
        config()->set('database.default', 'sqlite');
    }

    /** @return array{company: Company, branch: Branch, actor: User, order: Order} */
    private function commandFixture(string $environment): array
    {
        $this->app->detectEnvironment(static fn (): string => $environment);
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $actor = User::factory()->create(['email' => 'legacy-owner-'.$environment.'@example.com']);
        Membership::factory()->for($company)->for($actor)->create(['role' => MembershipRole::Owner]);
        $order = Order::factory()->for($branch)->create([
            'id' => 210,
            'company_id' => $company->id,
            'operational_number' => 156,
            'status' => OrderStatus::Cancelled,
            'total' => '99.00',
            'created_by' => $actor->id,
        ]);
        $this->bindGuard($environment);

        return compact('company', 'branch', 'actor', 'order');
    }

    private function bindGuard(string $environment): void
    {
        $guard = Mockery::mock(LegacyRestoreEnvironmentGuard::class);
        $guard->shouldReceive('assertAllowed')->once()->andReturn($environment);
        $this->app->instance(LegacyRestoreEnvironmentGuard::class, $guard);
    }

    private function commandArguments(bool $execute): PendingCommand
    {
        return $this->artisan('orders:restore-legacy-cancelled-paid', array_filter([
            'operational_number' => 156,
            '--company' => 1,
            '--branch' => 1,
            '--actor-email' => 'legacy-owner-production@example.com',
            '--allow-production' => true,
            '--execute' => $execute ?: null,
        ]));
    }

    /** @return array<string, mixed> */
    private function report(Order $order): array
    {
        return [
            'valid' => true,
            'order' => ['id' => $order->id, 'operational_number' => 156, 'total' => '99.00'],
            'payments' => [[
                'original_payment_id' => 152,
                'reversal_payment_id' => 157,
            ]],
            'dispatches' => [[
                'id' => 179,
                'previous_dispatch_status' => 'settled',
            ]],
            'items' => [[
                'id' => 320,
                'previous_item_status' => 'sent',
                'inventory_pairs' => [[
                    'original_movement_id' => 1304,
                    'reversal_movement_id' => 1321,
                    'quantity' => '100.000',
                ]],
            ]],
        ];
    }
}
