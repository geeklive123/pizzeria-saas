<?php

namespace Tests\Feature;

use App\Actions\DynamicQrAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterMixedPaymentAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialVersionConflict;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\FinancialPaymentAttempt;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Services\FinancialPaymentCoordinator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\IsolatedSqliteCommits;
use Tests\TestCase;

class FinancialPaymentCoordinatorTest extends TestCase
{
    use IsolatedSqliteCommits;

    public function test_stale_different_key_is_rejected_but_refreshed_partial_is_allowed(): void
    {
        [$order, $session, $user] = $this->fixture();
        $stale = Order::query()->findOrFail($order->id);
        $payments = app(RegisterPaymentAction::class);
        $first = $payments->execute($order, $session, PaymentMethod::Cash, '10.00', $user, 'first-payment', '10.00');
        $this->assertSame($first->id, $payments->execute($stale, $session, PaymentMethod::Cash, '10.00', $user, 'first-payment', '10.00')->id);
        try {
            $payments->execute($stale, $session, PaymentMethod::Cash, '10.00', $user, 'stale-payment', '10.00');
            $this->fail('The stale payment must not be recorded.');
        } catch (FinancialVersionConflict) {
            $this->assertDatabaseCount('payments', 1);
        }
        $payments->execute($stale->fresh(), $session, PaymentMethod::Cash, '10.00', $user, 'new-payment', '10.00');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_http_new_payment_requires_financial_version(): void
    {
        [$order, $session, $user] = $this->fixture();
        $this->actingAs($user)->withSession(['active_company_id' => $order->company_id, 'active_branch_id' => $order->branch_id])
            ->postJson(route('orders.payments.store', $order->ulid), ['method' => 'cash', 'amount' => '10.00', 'idempotency_key' => 'missing-version'])
            ->assertUnprocessable()->assertJsonValidationErrors('financial_version');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_http_rejects_stale_version_and_accepts_explicit_fresh_partial(): void
    {
        [$order, $session, $user] = $this->fixture();
        $version = $order->financial_version;
        $context = ['active_company_id' => $order->company_id, 'active_branch_id' => $order->branch_id];
        $payload = ['method' => 'cash', 'amount' => '10.00', 'received_amount' => '10.00', 'financial_version' => $version, 'idempotency_key' => 'http-first'];
        $this->actingAs($user)->withSession($context)->post(route('orders.payments.store', $order->ulid), $payload)->assertRedirect();
        $this->post(route('orders.payments.store', $order->ulid), $payload)->assertRedirect();
        $this->post(route('orders.payments.store', $order->ulid), array_replace($payload, ['idempotency_key' => 'http-stale']))
            ->assertStatus(409)->assertSee('pedido cambió');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('cash_movements')->where('type', 'sale_cash')->count());
        $this->getJson(route('orders.financial-attempt.show', $order->ulid))->assertOk()->assertJsonPath('financial_version', $order->fresh()->financial_version);
        $this->post(route('orders.payments.store', $order->ulid), array_replace($payload, ['idempotency_key' => 'http-fresh', 'financial_version' => $order->fresh()->financial_version]))->assertRedirect();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_partial_cash_attempt_is_persisted_and_same_key_is_idempotent(): void
    {
        [$order, $session, $user] = $this->fixture();
        $action = app(RegisterPaymentAction::class);
        $first = $action->execute($order, $session, PaymentMethod::Cash, '10.00', $user, 'cash-first', '10.00');
        $same = $action->execute($order->fresh(), $session, PaymentMethod::Cash, '10.00', $user, 'cash-first', '10.00');
        $second = $action->execute($order->fresh(), $session, PaymentMethod::Cash, '5.00', $user, 'cash-second', '5.00');

        $this->assertSame($first->id, $same->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('financial_payment_attempts', ['order_id' => $order->id, 'payment_id' => $first->id, 'status' => 'confirmed', 'active_order_id' => null]);
        $this->assertDatabaseHas('financial_payment_attempts', ['order_id' => $order->id, 'payment_id' => $second->id, 'status' => 'confirmed', 'active_order_id' => null]);
    }

    public function test_incompatible_cash_is_blocked_by_persistent_pending_attempt(): void
    {
        [$order, $session, $user] = $this->fixture();
        FinancialPaymentAttempt::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'active_order_id' => $order->id, 'cash_session_id' => $session->id, 'user_id' => $user->id,
            'idempotency_key' => 'pending-cash', 'method' => 'cash', 'amount' => '10.00', 'status' => 'pending',
        ]);

        try {
            app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '10.00', $user, 'another-cash');
            $this->fail('An incompatible payment must be blocked.');
        } catch (DomainException $exception) {
            $this->assertSame(FinancialPaymentCoordinator::UNCERTAIN, $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_reconciliation_preserves_identity_and_fences_the_old_revision(): void
    {
        [$order, $session, $user] = $this->fixture();
        $attempt = FinancialPaymentAttempt::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'active_order_id' => $order->id, 'cash_session_id' => $session->id, 'user_id' => $user->id,
            'idempotency_key' => 'recover-cash', 'method' => 'cash', 'amount' => '10.00', 'status' => 'pending',
        ]);
        $result = app(FinancialPaymentCoordinator::class)->reconcile($attempt, $user);
        $this->assertSame('retryable', $result->status);
        $this->assertSame(1, $result->revision);
        $this->assertSame($order->id, $result->active_order_id);
        try {
            DB::transaction(fn () => app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '10.00', $user, 'recover-cash', attemptRevision: 0));
            $this->fail('A fenced revision must not record payment.');
        } catch (DomainException $exception) {
            $this->assertSame(FinancialPaymentCoordinator::UNCERTAIN, $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $payment = app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '10.00', $user, 'recover-cash');
        $this->assertSame($payment->id, $attempt->fresh()->payment_id);
        $this->assertNull($attempt->fresh()->active_order_id);
    }

    public function test_qr_generation_is_blocked_by_an_unresolved_cash_attempt(): void
    {
        [$order, $session, $user] = $this->fixture();
        FinancialPaymentAttempt::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'active_order_id' => $order->id, 'cash_session_id' => $session->id, 'user_id' => $user->id,
            'idempotency_key' => 'pending-cash', 'method' => 'cash', 'amount' => '10.00', 'status' => 'pending',
        ]);
        config(['baneco.enabled' => true]);

        try {
            app(DynamicQrAction::class)->generate($order, $session, $user, 'new-qr');
            $this->fail('An incompatible QR must be blocked.');
        } catch (DomainException $exception) {
            $this->assertSame(FinancialPaymentCoordinator::UNCERTAIN, $exception->getMessage());
        }
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
    }

    public function test_http_status_and_reconciliation_reuse_the_persisted_attempt(): void
    {
        [$order, $session, $user] = $this->fixture();
        $attempt = FinancialPaymentAttempt::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'active_order_id' => $order->id, 'cash_session_id' => $session->id, 'user_id' => $user->id,
            'idempotency_key' => 'http-recovery', 'method' => 'cash', 'amount' => '10.00', 'status' => 'pending',
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $order->company_id, 'active_branch_id' => $order->branch_id]);
        $this->getJson(route('orders.financial-attempt.show', $order->ulid))->assertOk()
            ->assertJsonPath('attempt.ulid', $attempt->ulid)->assertJsonPath('attempt.idempotency_key', 'http-recovery');
        $this->postJson(route('orders.financial-attempt.reconcile', [$order->ulid, $attempt->ulid]))->assertOk()
            ->assertJsonPath('attempt.status', 'retryable')->assertJsonPath('attempt.active', true);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unprotected_payment_inside_an_existing_transaction_is_rejected(): void
    {
        [$order, $session, $user] = $this->fixture();
        try {
            DB::transaction(fn () => app(RegisterPaymentAction::class)
                ->execute($order, $session, PaymentMethod::Cash, '10.00', $user, 'nested-unprotected', '10.00'));
            $this->fail('An unprotected nested payment must not be recorded.');
        } catch (DomainException $exception) {
            $this->assertSame(FinancialPaymentCoordinator::UNCERTAIN, $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_nested_payment_cannot_reuse_another_cashiers_attempt(): void
    {
        [$order, $session, $user] = $this->fixture();
        $company = Company::query()->findOrFail($order->company_id);
        $other = User::factory()->create();
        Membership::factory()->for($company)->for($other)->owner()->create();
        $register = CashRegister::query()->create(['company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'name' => 'Otra caja', 'is_active' => true]);
        $otherSession = app(OpenCashSessionAction::class)->execute($register, '0.00', $other);
        FinancialPaymentAttempt::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'active_order_id' => $order->id, 'cash_session_id' => $session->id, 'user_id' => $user->id,
            'idempotency_key' => 'other-cashier', 'method' => 'cash', 'amount' => '10.00', 'status' => 'pending',
        ]);
        try {
            DB::transaction(fn () => app(RegisterPaymentAction::class)
                ->execute($order, $otherSession, PaymentMethod::Cash, '10.00', $other, 'other-cashier'));
            $this->fail('Another cashier must not reuse the pending operation.');
        } catch (DomainException $exception) {
            $this->assertSame(FinancialPaymentCoordinator::UNCERTAIN, $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mixed_cash_only_operation_can_be_reconciled_by_its_derived_key(): void
    {
        [$order, $session, $user] = $this->fixture();
        $payment = app(RegisterMixedPaymentAction::class)->execute($order, $session, '30.00', $user, 'mixed-cash-only', '30.00');
        $attempt = FinancialPaymentAttempt::query()->where('order_id', $order->id)->firstOrFail();
        $recovered = app(FinancialPaymentCoordinator::class)->reconcile($attempt, $user);
        $this->assertSame('confirmed', $recovered->status);
        $this->assertSame($payment->id, $recovered->payment_id);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_attempt_endpoint_does_not_expose_another_company(): void
    {
        [$order, $session, $user] = $this->fixture();
        $attempt = FinancialPaymentAttempt::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'active_order_id' => $order->id, 'cash_session_id' => $session->id, 'user_id' => $user->id,
            'idempotency_key' => 'isolated-attempt', 'method' => 'cash', 'amount' => '10.00', 'status' => 'pending',
        ]);
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $outsider = User::factory()->create();
        Membership::factory()->for($other)->for($outsider)->owner()->create();
        $this->actingAs($outsider)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id]);
        $this->getJson(route('orders.financial-attempt.show', $order->ulid))->assertNotFound();
        $this->postJson(route('orders.financial-attempt.reconcile', [$order->ulid, $attempt->ulid]))->assertNotFound();
    }

    private function fixture(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->owner()->create();
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $user);
        $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $user->id, 'status' => OrderStatus::ReadyForPayment, 'total' => '30.00', 'subtotal' => '30.00']);

        return [$order, $session, $user];
    }
}
