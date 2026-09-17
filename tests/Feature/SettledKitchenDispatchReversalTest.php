<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelSettledKitchenDispatchAction;
use App\Actions\CloseCashSessionAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\FinalizePerBatchTableAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\ReverseInventoryMovementAction;
use App\Enums\CashMovementType;
use App\Enums\InventoryMovementType;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\OrderCancellationAudit;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettledKitchenDispatchReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_cash_reversal_returns_only_the_selected_batch_and_audits_the_operation(): void
    {
        $f = $this->fixture(['cash' => '24.00']);
        $originalPayment = $f['first']->payments()->sole();
        $originalCashMovement = CashMovement::query()
            ->where('reference_type', Payment::class)
            ->where('reference_id', $originalPayment->id)
            ->sole();

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first'], $f['owner'], 'Cliente devolvió la primera tanda');

        $this->assertSame(KitchenDispatchStatus::Cancelled, $f['first']->refresh()->status);
        $this->assertSame(OrderItemStatus::Cancelled, $f['firstItem']->refresh()->status);
        $this->assertSame(KitchenDispatchStatus::Settled, $f['second']->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $f['secondItem']->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertSame('12.00', $f['order']->total);
        $this->assertSame('9.000', $this->stock($f));

        $this->assertSame(PaymentStatus::Reversed, $originalPayment->refresh()->status);
        $reversal = $originalPayment->reversals()->sole();
        $this->assertSame('24.00', $reversal->amount);
        $this->assertSame($f['session']->id, $reversal->cash_session_id);
        $cashReversal = CashMovement::query()->where('reference_type', Payment::class)
            ->where('reference_id', $reversal->id)->sole();
        $this->assertSame(CashMovementType::Reversal, $cashReversal->type);
        $this->assertSame($originalCashMovement->id, $cashReversal->reversal_of_id);
        $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($f['session'])['expected_cash']);

        $audit = OrderCancellationAudit::query()->where('kitchen_dispatch_id', $f['first']->id)
            ->where('scope', 'kitchen_dispatch')->sole();
        $this->assertSame('Cliente devolvió la primera tanda', $audit->cancellation_reason);
        $this->assertSame($f['owner']->id, $audit->cancelled_by);
        $this->assertSame('settled_dispatch', $audit->snapshot['reversal_type']);
        $this->assertSame('36.00', $audit->snapshot['order_total_before']);
        $this->assertSame('12.00', $audit->snapshot['order_total_after']);
        $this->assertCount(1, $audit->snapshot['payments']);
        $this->assertCount(1, $audit->snapshot['items']);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Reversal)->count());

        $this->expectException(DomainException::class);
        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first']->refresh(), $f['owner'], 'Segundo intento');
    }

    public function test_closed_cash_session_uses_its_open_traceable_successor(): void
    {
        $f = $this->fixture(['cash' => '24.00']);
        $originalPayment = $f['first']->payments()->sole();
        $originalMovement = CashMovement::query()->where('reference_type', Payment::class)
            ->where('reference_id', $originalPayment->id)->sole();
        $originalMovementSnapshot = $originalMovement->getRawOriginal();
        app(CloseCashSessionAction::class)->execute($f['session'], '24.00', $f['owner']);
        $closedSnapshot = $f['session']->refresh()->getRawOriginal();
        $successor = app(OpenCashSessionAction::class)->execute($f['register'], '24.00', $f['owner']);

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first'], $f['owner'], 'Devolución en turno sucesor');

        $reversalPayment = $originalPayment->reversals()->sole();
        $reversalMovement = CashMovement::query()->where('reference_type', Payment::class)
            ->where('reference_id', $reversalPayment->id)->sole();
        $this->assertSame($closedSnapshot, $f['session']->refresh()->getRawOriginal());
        $this->assertSame($originalMovementSnapshot, $originalMovement->refresh()->getRawOriginal());
        $this->assertSame($successor->id, $reversalPayment->cash_session_id);
        $this->assertSame($successor->id, $reversalMovement->cash_session_id);
        $this->assertSame($originalMovement->id, $reversalMovement->reversal_of_id);
        $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($successor)['expected_cash']);
    }

    public function test_closed_cash_session_without_successor_aborts_everything(): void
    {
        $f = $this->fixture(['cash' => '24.00']);
        app(CloseCashSessionAction::class)->execute($f['session'], '24.00', $f['owner']);
        $stock = $this->stock($f);
        $paymentCount = Payment::query()->count();
        $movementCount = CashMovement::query()->count();

        try {
            app(CancelSettledKitchenDispatchAction::class)
                ->execute($f['first'], $f['owner'], 'No existe turno sucesor');
            $this->fail('La operación debía abortarse sin un sucesor abierto.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no existe un único turno sucesor trazable', $exception->getMessage());
        }

        $this->assertSame(KitchenDispatchStatus::Settled, $f['first']->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $f['firstItem']->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertSame($stock, $this->stock($f));
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($movementCount, CashMovement::query()->count());
        $this->assertDatabaseCount('order_cancellation_audits', 0);
    }

    public function test_qr_reversal_creates_no_cash_movement(): void
    {
        $f = $this->fixture(['qr' => '24.00']);
        $cashMovementCount = CashMovement::query()->count();

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first'], $f['owner'], 'Reversión QR');

        $original = $f['first']->payments()->where('reversal_of_id', null)->sole();
        $this->assertSame(PaymentStatus::Reversed, $original->refresh()->status);
        $this->assertSame(PaymentMethod::Qr, $original->reversals()->sole()->method);
        $this->assertSame($cashMovementCount, CashMovement::query()->count());
    }

    public function test_mixed_payment_reverses_each_line_and_only_cash_has_a_compensation(): void
    {
        $f = $this->fixture(['cash' => '10.00', 'qr' => '14.00']);

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first'], $f['owner'], 'Reversión mixta');

        $originals = $f['first']->payments()->whereNull('reversal_of_id')->orderBy('id')->get();
        $this->assertCount(2, $originals);
        foreach ($originals as $original) {
            $this->assertSame(PaymentStatus::Reversed, $original->refresh()->status);
            $this->assertSame($original->method, $original->reversals()->sole()->method);
            $this->assertSame($original->amount, $original->reversals()->sole()->amount);
        }
        $this->assertSame(1, CashMovement::query()->where('type', CashMovementType::Reversal)->count());
    }

    public function test_http_endpoint_requires_permission_and_preserves_company_and_branch_boundaries(): void
    {
        $f = $this->fixture(['qr' => '24.00']);
        $cashier = User::factory()->create();
        Membership::factory()->for($f['company'])->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $payload = ['reason' => 'Intento no autorizado', 'confirmed' => '1'];

        $this->actingInContext($cashier, $f['company'], $f['branch'])
            ->post(route('orders.dispatches.cancel-settled', [$f['order']->ulid, $f['first']->ulid]), $payload)
            ->assertForbidden();

        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingInContext($otherOwner, $otherCompany, $otherBranch)
            ->post(route('orders.dispatches.cancel-settled', [$f['order']->ulid, $f['first']->ulid]), $payload)
            ->assertNotFound();

        $sameCompanyOtherBranch = Branch::factory()->for($f['company'])->create();
        $this->actingInContext($f['owner'], $f['company'], $sameCompanyOtherBranch)
            ->post(route('orders.dispatches.cancel-settled', [$f['order']->ulid, $f['first']->ulid]), $payload)
            ->assertNotFound();

        $this->assertSame(KitchenDispatchStatus::Settled, $f['first']->refresh()->status);
        $this->assertDatabaseCount('order_cancellation_audits', 0);
    }

    public function test_inventory_failure_rolls_back_payments_cash_dispatch_items_and_audit(): void
    {
        $f = $this->fixture(['cash' => '10.00', 'qr' => '14.00']);
        $paymentCount = Payment::query()->count();
        $cashMovementCount = CashMovement::query()->count();
        $stock = $this->stock($f);
        $this->mock(ReverseInventoryMovementAction::class)
            ->shouldReceive('execute')->once()->andThrow(new DomainException('Fallo de inventario'));

        $this->actingInContext($f['owner'], $f['company'], $f['branch'])
            ->post(route('orders.dispatches.cancel-settled', [$f['order']->ulid, $f['first']->ulid]), [
                'reason' => 'Probar rollback',
                'confirmed' => '1',
            ])->assertSessionHasErrors('dispatch');

        $this->assertSame(OrderStatus::Paid, $f['order']->refresh()->status);
        $this->assertSame('36.00', $f['order']->total);
        $this->assertSame(KitchenDispatchStatus::Settled, $f['first']->refresh()->status);
        $this->assertSame(OrderItemStatus::Ready, $f['firstItem']->refresh()->status);
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame(3, Payment::query()->where('status', PaymentStatus::Completed)->count());
        $this->assertSame($cashMovementCount, CashMovement::query()->count());
        $this->assertSame($stock, $this->stock($f));
        $this->assertDatabaseCount('order_cancellation_audits', 0);
    }

    private function fixture(array $firstPayments): array
    {
        $company = Company::factory()->create(['table_charge_mode' => TableChargeMode::PerBatch]);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja principal',
            'is_active' => true,
        ]);
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad',
            'symbol' => 'u',
            'type' => UnitType::Unit,
        ]);
        $product = Product::factory()->for($company)->create([
            'name' => 'Bebida',
            'type' => ProductType::Beverage,
        ]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create([
            'name' => 'Unidad',
            'price' => '12.00',
            'requires_preparation' => false,
        ]);
        $inventory = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'product_variant_id' => $variant->id,
            'name' => 'Bebida',
            'is_active' => true,
        ]);
        app(ApplyInventoryMovementAction::class)->execute(
            $company,
            $branch,
            $inventory,
            InventoryMovementType::AdjustmentIn,
            '10.000',
            '1.000000',
            $owner,
            reason: 'Stock inicial de prueba',
        );
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)
            ->execute($company, $branch, $table, $owner, null, TableChargeMode::PerBatch);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);

        $firstItem = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);
        $first = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        foreach ($firstPayments as $method => $amount) {
            app(RegisterPaymentAction::class)->execute(
                $order->refresh(),
                $session,
                PaymentMethod::from($method),
                $amount,
                $owner,
                'first-'.$method,
                receivedAmount: $method === 'cash' ? $amount : null,
                reference: $method === 'qr' ? 'QR-PRIMERA' : null,
                dispatch: $first->refresh(),
            );
        }

        $secondItem = app(AddOrderItemAction::class)->execute($order->refresh(), $variant, '1.000', $owner);
        $second = app(DispatchOrderToKitchenAction::class)->execute($order->refresh(), $owner);
        app(RegisterPaymentAction::class)->execute(
            $order->refresh(),
            $session,
            PaymentMethod::Qr,
            '12.00',
            $owner,
            'second-qr',
            reference: 'QR-SEGUNDA',
            dispatch: $second,
        );
        app(FinalizePerBatchTableAction::class)->execute($order->refresh(), $owner);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(KitchenDispatchStatus::Settled, $first->refresh()->status);
        $this->assertSame(KitchenDispatchStatus::Settled, $second->refresh()->status);
        $this->assertSame('7.000', InventoryStock::query()
            ->where('branch_id', $branch->id)->where('inventory_item_id', $inventory->id)->value('quantity'));

        return compact(
            'company',
            'branch',
            'owner',
            'register',
            'session',
            'inventory',
            'order',
            'first',
            'firstItem',
            'second',
            'secondItem',
        );
    }

    private function stock(array $fixture): string
    {
        return InventoryStock::query()
            ->where('branch_id', $fixture['branch']->id)
            ->where('inventory_item_id', $fixture['inventory']->id)
            ->value('quantity');
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }

    public function test_history_ui_offers_whole_paid_batch_reversal_and_blocks_individual_products(): void
    {
        $f = $this->fixture(['cash' => '10.00', 'qr' => '14.00']);
        $this->actingInContext($f['owner'], $f['company'], $f['branch'])
            ->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('Revertir tanda pagada')
            ->assertSee('Revertir tanda pagada #')
            ->assertSee('Importe de la tanda:')
            ->assertSee('revierte los pagos asociados')
            ->assertSee('Esta tanda ya fue pagada. Para anular productos debes revertir la tanda completa.')
            ->assertDontSee('Anular ítem')
            ->assertDontSee('Anular tanda completa');

        $this->post(route('orders.dispatches.cancel-settled', [$f['order']->ulid, $f['first']->ulid]), [
            'reason' => 'Error confirmado desde historial',
            'confirmed' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('REVERTIDA')
            ->assertSee('Error confirmado desde historial')
            ->assertSee($f['owner']->name)
            ->assertDontSee(route('orders.dispatches.items.cancel', [
                $f['order']->ulid,
                $f['first']->ulid,
                $f['firstItem']->ulid,
            ]), false);
    }

    public function test_qr_can_be_reversed_after_its_historical_session_closed_without_cash_successor(): void
    {
        $f = $this->fixture(['qr' => '24.00']);
        app(CloseCashSessionAction::class)->execute($f['session'], '0.00', $f['owner']);
        $closedSnapshot = $f['session']->refresh()->getRawOriginal();
        $cashMovementCount = CashMovement::query()->count();

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first'], $f['owner'], 'QR de turno cerrado');

        $original = $f['first']->payments()->whereNull('reversal_of_id')->sole();
        $this->assertSame(PaymentStatus::Reversed, $original->refresh()->status);
        $this->assertSame($f['session']->id, $original->reversals()->sole()->cash_session_id);
        $this->assertSame($closedSnapshot, $f['session']->refresh()->getRawOriginal());
        $this->assertSame($cashMovementCount, CashMovement::query()->count());
    }

    public function test_ambiguous_cash_successor_chain_aborts_everything(): void
    {
        $f = $this->fixture(['cash' => '24.00']);
        app(CloseCashSessionAction::class)->execute($f['session'], '24.00', $f['owner']);
        $successor = app(OpenCashSessionAction::class)->execute($f['register'], '24.00', $f['owner']);
        $ambiguous = $successor->replicate();
        $ambiguous->forceFill([
            'previous_cash_session_id' => $f['session']->id,
            'active_cash_register_id' => null,
            'status' => 'closed',
            'closed_by' => $f['owner']->id,
            'closed_at' => now(),
        ])->save();

        try {
            app(CancelSettledKitchenDispatchAction::class)
                ->execute($f['first'], $f['owner'], 'Cadena ambigua');
            $this->fail('La operación debía abortarse ante dos sucesores posibles.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no existe un único turno sucesor trazable', $exception->getMessage());
        }

        $this->assertSame(KitchenDispatchStatus::Settled, $f['first']->refresh()->status);
        $this->assertSame(PaymentStatus::Completed, $f['first']->payments()->sole()->status);
        $this->assertDatabaseCount('order_cancellation_audits', 0);
    }

    public function test_mysql_executes_row_locks_for_the_relevant_reversal_rows(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This lock assertion requires MySQL/InnoDB.');
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $queries[] = $sql;
            }
        });
        $f = $this->fixture(['cash' => '10.00', 'qr' => '14.00']);

        app(CancelSettledKitchenDispatchAction::class)
            ->execute($f['first'], $f['owner'], 'Verificar locks MySQL');

        foreach (['cash_sessions', 'orders', 'kitchen_dispatches', 'payments', 'order_items', 'inventory_movements'] as $table) {
            $this->assertTrue(
                collect($queries)->contains(fn (string $sql): bool => str_contains($sql, "`{$table}`")),
                "Expected a SELECT ... FOR UPDATE query for {$table}.",
            );
        }
        $this->assertSame(1, $f['first']->payments()->whereNotNull('reversal_of_id')->where('method', 'cash')->count());
        $this->assertSame(1, CashMovement::query()->where('type', CashMovementType::Reversal)->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Reversal)->count());
    }
}
