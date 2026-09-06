<?php

namespace Tests\Feature;

use App\Actions\CloseCashSessionAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\TransferOrderPaymentsToCashSessionAction;
use App\Enums\CashClosingBalanceStatus;
use App\Enums\CashMovementType;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\KitchenDispatch;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashSessionTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_transfers_mixed_payments_and_preserves_operational_records(): void
    {
        [$company, $branch, $owner] = $this->context();
        $valeria = $this->member($company, MembershipRole::Cashier, 'Valeria');
        $andrea = $this->member($company, MembershipRole::Cashier, 'Andrea');
        $source = $this->cashSession($company, $branch, $valeria, 'Caja Valeria', '10.00');
        $destination = $this->cashSession($company, $branch, $andrea, 'Caja Andrea', '20.00');
        $order = $this->servedOrder($company, $branch, $owner, '100.00');
        $itemBefore = \DB::table('order_items')->where('order_id', $order->id)->first();
        $dispatch = KitchenDispatch::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $order->id,
            'sequence_number' => 1, 'status' => KitchenDispatchStatus::Settled,
            'dispatched_at' => now(), 'dispatched_by' => $owner->id,
            'gross_subtotal' => '100.00', 'pizza_base_subtotal' => '0.00',
            'extras_subtotal' => '0.00', 'other_subtotal' => '100.00',
            'discount_percentage' => '0.00', 'discount_total' => '0.00',
            'total' => '100.00', 'settled_at' => now(),
        ]);
        $dispatchBefore = \DB::table('kitchen_dispatches')->where('id', $dispatch->id)->first();
        $cash = app(RegisterPaymentAction::class)->execute($order, $source, PaymentMethod::Cash, '40.00', $valeria, 'transfer-cash', '40.00');
        $qr = app(RegisterPaymentAction::class)->execute($order, $source, PaymentMethod::Qr, '60.00', $valeria, 'transfer-qr');
        $inventoryCounts = [
            'stocks' => \DB::table('inventory_stocks')->count(),
            'movements' => \DB::table('inventory_movements')->count(),
            'reservations' => \DB::table('inventory_reservations')->count(),
        ];

        $audit = app(TransferOrderPaymentsToCashSessionAction::class)
            ->execute($company, $order->refresh(), $destination, $owner, 'Venta registrada con la sesión de Valeria.');

        foreach ([$cash, $qr] as $payment) {
            $this->assertSame($destination->id, $payment->refresh()->cash_session_id);
            $this->assertSame($andrea->id, $payment->received_by);
        }
        $this->assertSame(PaymentMethod::Cash, $cash->method);
        $this->assertSame('40.00', $cash->amount);
        $this->assertSame(PaymentMethod::Qr, $qr->method);
        $this->assertSame('60.00', $qr->amount);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertEquals($itemBefore, \DB::table('order_items')->where('order_id', $order->id)->first());
        $this->assertEquals($dispatchBefore, \DB::table('kitchen_dispatches')->where('id', $dispatch->id)->first());
        $this->assertSame($inventoryCounts['stocks'], \DB::table('inventory_stocks')->count());
        $this->assertSame($inventoryCounts['movements'], \DB::table('inventory_movements')->count());
        $this->assertSame($inventoryCounts['reservations'], \DB::table('inventory_reservations')->count());
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $source->id,
            'type' => CashMovementType::AdministrativeTransferOut->value,
            'reference_id' => $cash->id,
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $destination->id,
            'type' => CashMovementType::AdministrativeTransferIn->value,
            'reference_id' => $cash->id,
            'created_by' => $andrea->id,
            'authorized_by' => $owner->id,
        ]);
        $this->assertSame('10.00', app(CashSessionSummaryService::class)->calculate($source)['expected_cash']);
        $this->assertSame('60.00', app(CashSessionSummaryService::class)->calculate($destination)['expected_cash']);
        $this->assertSame('10.00', $source->refresh()->expected_cash_amount);
        $this->assertSame('60.00', $destination->refresh()->expected_cash_amount);
        $this->assertSame([$source->id], $audit->source_cash_session_ids);
        $this->assertSame([$valeria->id], $audit->source_cashier_ids);
        $this->assertEqualsCanonicalizing([$cash->id, $qr->id], $audit->payment_ids);
        $this->assertCount(2, $audit->compensating_cash_movement_ids);
    }

    public function test_admin_can_transfer_qr_and_cashier_receives_403(): void
    {
        [$company, $branch, $owner] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin, 'Admin');
        $cashier = $this->member($company, MembershipRole::Cashier, 'Cashier');
        $source = $this->cashSession($company, $branch, $cashier, 'Origen');
        $destination = $this->cashSession($company, $branch, $admin, 'Destino');
        $order = $this->servedOrder($company, $branch, $owner, '30.00');
        $payment = app(RegisterPaymentAction::class)->execute($order, $source, PaymentMethod::Qr, '30.00', $cashier, 'admin-qr');

        $this->actingInContext($cashier, $company, $branch)
            ->get(route('orders.cash-session-transfer.create', $order->ulid))
            ->assertForbidden();
        $this->actingInContext($cashier, $company, $branch)
            ->post(route('orders.cash-session-transfer.store', $order->ulid), [
                'destination_session' => $destination->ulid, 'reason' => 'No autorizado',
            ])->assertForbidden();
        $this->actingInContext($admin, $company, $branch)
            ->get(route('orders.cash-session-transfer.create', $order->ulid))
            ->assertOk()->assertSee('Transferir a otra cajera')->assertSee('Abierta')->assertSee('Destino');
        $this->actingInContext($admin, $company, $branch)
            ->post(route('orders.cash-session-transfer.store', $order->ulid), [
                'destination_session' => $destination->ulid, 'reason' => '',
            ])->assertSessionHasErrors('reason');
        $this->actingInContext($admin, $company, $branch)
            ->post(route('orders.cash-session-transfer.store', $order->ulid), [
                'destination_session' => $destination->ulid, 'reason' => 'Cobro realizado por Admin',
            ])->assertRedirect(route('orders.show', $order->ulid));

        $this->assertSame($destination->id, $payment->refresh()->cash_session_id);
        $this->assertSame($admin->id, $payment->received_by);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_closed_sessions_keep_counted_cash_and_recalculate_historical_differences(): void
    {
        [$company, $branch, $owner] = $this->context();
        $sourceUser = $this->member($company, MembershipRole::Cashier, 'Origen');
        $destinationUser = $this->member($company, MembershipRole::Cashier, 'Destino');
        $source = $this->cashSession($company, $branch, $sourceUser, 'Caja origen');
        $destination = $this->cashSession($company, $branch, $destinationUser, 'Caja destino', '20.00');
        $order = $this->servedOrder($company, $branch, $owner, '50.00');
        app(RegisterPaymentAction::class)->execute($order, $source, PaymentMethod::Cash, '50.00', $sourceUser, 'closed-transfer', '50.00');
        $source = app(CloseCashSessionAction::class)->execute($source, '50.00', $owner);
        $destination = app(CloseCashSessionAction::class)->execute($destination, '20.00', $owner);

        app(TransferOrderPaymentsToCashSessionAction::class)
            ->execute($company, $order->refresh(), $destination, $owner, 'Corrección de cierre histórico');

        $source->refresh();
        $destination->refresh();
        $this->assertSame('50.00', $source->counted_cash_amount);
        $this->assertSame('0.00', $source->expected_cash_amount);
        $this->assertSame('50.00', $source->difference_amount);
        $this->assertSame(CashClosingBalanceStatus::Over, $source->closing_balance_status);
        $this->assertSame('20.00', $destination->counted_cash_amount);
        $this->assertSame('70.00', $destination->expected_cash_amount);
        $this->assertSame('-50.00', $destination->difference_amount);
        $this->assertSame(CashClosingBalanceStatus::Short, $destination->closing_balance_status);
        $this->assertSame('closed', $destination->status->value);
    }

    public function test_transfer_rejects_other_company_other_branch_empty_reason_and_same_session(): void
    {
        [$company, $branch, $owner] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier, 'Origen');
        $source = $this->cashSession($company, $branch, $cashier, 'Origen');
        $order = $this->servedOrder($company, $branch, $owner, '10.00');
        app(RegisterPaymentAction::class)->execute($order, $source, PaymentMethod::Qr, '10.00', $cashier, 'validation-qr');
        $otherBranch = Branch::factory()->for($company)->create();
        $otherBranchSession = $this->cashSession($company, $otherBranch, $cashier, 'Otra sucursal');
        $otherCompany = Company::factory()->create();
        $otherCompanyBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $otherCompanySession = $this->cashSession($otherCompany, $otherCompanyBranch, $otherOwner, 'Otra empresa');
        $action = app(TransferOrderPaymentsToCashSessionAction::class);

        foreach ([
            [$otherBranchSession, 'Sucursal incorrecta'],
            [$otherCompanySession, 'Empresa incorrecta'],
            [$source, ''],
            [$source, 'Sin cambios'],
        ] as [$destination, $reason]) {
            try {
                $action->execute($company, $order->refresh(), $destination, $owner, $reason);
                $this->fail('La transferencia inválida debía rechazarse.');
            } catch (DomainException) {
                $this->assertDatabaseCount('cash_session_transfers', 0);
            }
        }
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payments_from_multiple_sessions_consolidate_and_repetition_does_not_duplicate_movements(): void
    {
        [$company, $branch, $owner] = $this->context();
        $firstUser = $this->member($company, MembershipRole::Cashier, 'Primera');
        $secondUser = $this->member($company, MembershipRole::Cashier, 'Segunda');
        $destinationUser = $this->member($company, MembershipRole::Cashier, 'Destino');
        $first = $this->cashSession($company, $branch, $firstUser, 'Caja 1');
        $second = $this->cashSession($company, $branch, $secondUser, 'Caja 2');
        $destination = $this->cashSession($company, $branch, $destinationUser, 'Caja 3');
        $order = $this->servedOrder($company, $branch, $owner, '100.00');
        app(RegisterPaymentAction::class)->execute($order, $first, PaymentMethod::Cash, '40.00', $firstUser, 'multi-cash', '40.00');
        app(RegisterPaymentAction::class)->execute($order, $second, PaymentMethod::Qr, '60.00', $secondUser, 'multi-qr');
        $action = app(TransferOrderPaymentsToCashSessionAction::class);
        $audit = $action->execute($company, $order->refresh(), $destination, $owner, 'Consolidar cobros');

        $this->assertSame([$first->id, $second->id], $audit->source_cash_session_ids);
        $this->assertSame([$destination->id], Payment::query()->where('order_id', $order->id)->pluck('cash_session_id')->unique()->all());
        $movementCount = CashMovement::query()->count();
        try {
            $action->execute($company, $order->refresh(), $destination, $owner, 'Repetición');
            $this->fail('Una repetición sin cambios debía rechazarse.');
        } catch (DomainException $exception) {
            $this->assertSame('El pedido ya pertenece a esa sesión.', $exception->getMessage());
        }
        $this->assertSame($movementCount, CashMovement::query()->count());
        $this->assertDatabaseCount('cash_session_transfers', 1);
    }

    public function test_second_transfer_to_another_session_is_audited_without_new_payments(): void
    {
        [$company, $branch, $owner] = $this->context();
        $firstUser = $this->member($company, MembershipRole::Cashier, 'Primera');
        $secondUser = $this->member($company, MembershipRole::Cashier, 'Segunda');
        $thirdUser = $this->member($company, MembershipRole::Cashier, 'Tercera');
        $first = $this->cashSession($company, $branch, $firstUser, 'Caja 1');
        $second = $this->cashSession($company, $branch, $secondUser, 'Caja 2');
        $third = $this->cashSession($company, $branch, $thirdUser, 'Caja 3');
        $order = $this->servedOrder($company, $branch, $owner, '25.00');
        $payment = app(RegisterPaymentAction::class)->execute($order, $first, PaymentMethod::Cash, '25.00', $firstUser, 'second-transfer', '25.00');
        $action = app(TransferOrderPaymentsToCashSessionAction::class);

        $action->execute($company, $order->refresh(), $second, $owner, 'Primera corrección');
        $action->execute($company, $order->refresh(), $third, $owner, 'Segunda corrección');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_session_transfers', 2);
        $this->assertSame($third->id, $payment->refresh()->cash_session_id);
        $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($first)['expected_cash']);
        $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($second)['expected_cash']);
        $this->assertSame('25.00', app(CashSessionSummaryService::class)->calculate($third)['expected_cash']);
    }

    public function test_action_rejects_non_admin(): void
    {
        [$company, $branch, $owner] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier, 'Cashier');
        $destinationUser = $this->member($company, MembershipRole::Cashier, 'Destino');
        $source = $this->cashSession($company, $branch, $cashier, 'Origen');
        $destination = $this->cashSession($company, $branch, $destinationUser, 'Destino');
        $order = $this->servedOrder($company, $branch, $owner, '15.00');
        app(RegisterPaymentAction::class)->execute($order, $source, PaymentMethod::Qr, '15.00', $cashier, 'invalid-owner');

        $this->expectException(AuthorizationException::class);
        app(TransferOrderPaymentsToCashSessionAction::class)
            ->execute($company, $order->refresh(), $destination, $cashier, 'No autorizado');
    }

    public function test_action_rejects_order_without_completed_payments_and_invalid_destination_holder(): void
    {
        [$company, $branch, $owner] = $this->context();
        $destinationUser = $this->member($company, MembershipRole::Cashier, 'Destino');
        $destination = $this->cashSession($company, $branch, $destinationUser, 'Destino');
        $order = $this->servedOrder($company, $branch, $owner, '15.00');
        $order->forceFill(['status' => OrderStatus::Paid, 'closed_at' => now()])->save();
        $action = app(TransferOrderPaymentsToCashSessionAction::class);

        try {
            $action->execute($company, $order, $destination, $owner, 'Sin pagos');
            $this->fail('Un pedido sin pagos completados debía rechazarse.');
        } catch (DomainException $exception) {
            $this->assertSame('El pedido no tiene pagos completados.', $exception->getMessage());
        }

        $sourceUser = $this->member($company, MembershipRole::Cashier, 'Origen');
        $source = $this->cashSession($company, $branch, $sourceUser, 'Origen');
        $paidOrder = $this->servedOrder($company, $branch, $owner, '15.00');
        app(RegisterPaymentAction::class)->execute($paidOrder, $source, PaymentMethod::Qr, '15.00', $sourceUser, 'invalid-holder');
        Membership::query()->where('company_id', $company->id)->where('user_id', $destinationUser->id)->update(['is_active' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La sesión destino no tiene un titular válido para esta empresa.');
        $action->execute($company, $paidOrder->refresh(), $destination, $owner, 'Titular inválido');
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create(['name' => 'Owner']);
        Membership::factory()->for($company)->for($owner)->owner()->create();

        return [$company, $branch, $owner];
    }

    private function member(Company $company, MembershipRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function cashSession(Company $company, Branch $branch, User $user, string $name, string $opening = '0.00'): CashSession
    {
        $register = CashRegister::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => $name, 'is_active' => true,
        ]);

        return app(OpenCashSessionAction::class)->execute($register, $opening, $user);
    }

    private function servedOrder(Company $company, Branch $branch, User $user, string $total): Order
    {
        $order = Order::factory()->for($branch)->create([
            'company_id' => $company->id, 'subtotal' => $total,
            'total' => $total, 'created_by' => $user->id,
        ]);
        OrderItem::factory()->for($order)->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'unit_price' => $total, 'line_total' => $total,
            'status' => OrderItemStatus::Served, 'served_at' => now(),
            'created_by' => $user->id,
        ]);

        return $order;
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
