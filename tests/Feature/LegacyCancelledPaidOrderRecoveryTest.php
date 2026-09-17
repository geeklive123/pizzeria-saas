<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelPaidOrderAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\RestoreLegacyCancelledPaidOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderCancellationScope;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Membership;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitabilityReportService;
use App\Services\ReportDateRangeService;
use App\Services\SalesReportService;
use App\Support\LegacyManifestTimestamp;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyCancelledPaidOrderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_dry_run_is_read_only_and_reports_complete_legacy_evidence(): void
    {
        $fixture = $this->fixture();
        $before = $this->databaseCounts();

        $report = app(RestoreLegacyCancelledPaidOrderAction::class)
            ->dryRun($fixture['order']->refresh(), $fixture['actor']);

        $this->assertTrue($report['valid']);
        $this->assertSame('legacy_administrative', $report['recovery_type']);
        $this->assertSame($fixture['order']->id, $report['order']['id']);
        $this->assertSame(OrderStatus::Cancelled->value, $report['order']['status']);
        $this->assertCount(1, $report['payments']);
        $this->assertSame(PaymentMethod::Qr->value, $report['payments'][0]['method']);
        $this->assertSame($fixture['payment']->id, $report['payments'][0]['original_payment_id']);
        $this->assertSame($fixture['reversal']->id, $report['payments'][0]['reversal_payment_id']);
        $this->assertSame(KitchenDispatchStatus::Settled->value, $report['dispatches'][0]['previous_dispatch_status']);
        $this->assertSame(OrderItemStatus::Sent->value, $report['items'][0]['previous_item_status']);
        $this->assertCount(6, $report['items'][0]['inventory_pairs']);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame(OrderStatus::Cancelled, $fixture['order']->refresh()->status);

        try {
            app(RestoreLegacyCancelledPaidOrderAction::class)->execute(
                $fixture['order']->refresh(),
                $fixture['actor'],
                ['order.id' => -1],
            );
            $this->fail('A changed manifest had to abort the locked execution.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('expected manifest', $exception->getMessage());
        }
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_production_utc_order_timestamps_match_the_bolivia_manifest_strictly(): void
    {
        $fixture = $this->fixture();
        DB::table('orders')->where('id', $fixture['order']->id)->update([
            'opened_at' => '2026-09-17 00:32:17',
            'closed_at' => '2026-09-17 01:01:49',
            'cancelled_at' => '2026-09-17 02:58:02',
        ]);
        DB::table('order_items')->where('id', $fixture['item']->id)->update([
            'cancelled_at' => '2026-09-17 02:58:02',
        ]);

        $expectations = [
            'order.opened_at_utc' => LegacyManifestTimestamp::historicalToUtc('2026-09-16 20:32:17'),
            'order.closed_at_utc' => LegacyManifestTimestamp::historicalToUtc('2026-09-16 21:01:49'),
            'order.cancelled_at_utc' => LegacyManifestTimestamp::historicalToUtc('2026-09-16 22:58:02'),
        ];
        $action = app(RestoreLegacyCancelledPaidOrderAction::class);
        $report = $action->dryRun($fixture['order']->refresh(), $fixture['actor'], $expectations);

        $this->assertSame('2026-09-17 00:32:17', $report['order']['opened_at_utc']);
        $this->assertSame('2026-09-17 01:01:49', $report['order']['closed_at_utc']);
        $this->assertSame('2026-09-17 02:58:02', $report['order']['cancelled_at_utc']);

        foreach (['2026-09-17 01:01:50', '2026-09-17 01:01:48'] as $differentInstant) {
            DB::table('orders')->where('id', $fixture['order']->id)->update(['closed_at' => $differentInstant]);

            try {
                $action->dryRun($fixture['order']->refresh(), $fixture['actor'], $expectations);
                $this->fail('A one-second timestamp difference had to reject the manifest.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('order.closed_at_utc', $exception->getMessage());
            }
        }
    }

    public function test_qr_legacy_recovery_restores_state_with_compensating_ledgers_and_complete_audit(): void
    {
        $fixture = $this->fixture();
        $historicalMovements = InventoryMovement::query()->whereIn('id', [
            ...$fixture['original_movement_ids'],
            ...$fixture['reversal_movement_ids'],
        ])->get()->keyBy('id')->map->getAttributes()->all();
        $reservationState = InventoryReservation::query()->whereIn('id', $fixture['reservation_ids'])
            ->get()->keyBy('id')->map->getAttributes()->all();
        $originalClosedAt = $fixture['order']->closed_at;
        $originalCancelledAt = $fixture['order']->cancelled_at;
        $cashMovementCount = CashMovement::query()->count();

        $audit = app(RestoreLegacyCancelledPaidOrderAction::class)
            ->execute($fixture['order']->refresh(), $fixture['actor']);

        $order = $fixture['order']->refresh();
        $item = $fixture['item']->refresh();
        $dispatch = $fixture['dispatch']->refresh();
        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $compensation = $payments->sole(fn (Payment $payment): bool => $payment->status === PaymentStatus::Completed);
        $newMovements = InventoryMovement::query()->where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', InventoryMovementType::OrderConsumption->value)
            ->whereNotIn('id', $fixture['original_movement_ids'])->get();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(OrderItemStatus::Sent, $item->status);
        $this->assertSame(KitchenDispatchStatus::Settled, $dispatch->status);
        $this->assertSame($fixture['operational_number'], $order->operational_number);
        $this->assertSame('99.00', $order->total);
        $this->assertTrue($originalClosedAt->equalTo($order->closed_at));
        $this->assertTrue($originalCancelledAt->equalTo($order->cancelled_at));
        $this->assertSame($fixture['actor']->id, $order->cancelled_by);
        $this->assertSame('Error legacy', $order->cancellation_reason);

        $this->assertCount(3, $payments);
        $this->assertSame(PaymentStatus::Reversed, $fixture['payment']->refresh()->status);
        $this->assertSame(PaymentStatus::Reversed, $fixture['reversal']->refresh()->status);
        $this->assertSame(PaymentMethod::Qr, $compensation->method);
        $this->assertSame('99.00', $compensation->amount);
        $this->assertNull($compensation->reversal_of_id);
        $this->assertSame('legacy-restore-order-'.$order->id.'-payment-'.$fixture['payment']->id, $compensation->idempotency_key);
        $this->assertTrue($fixture['payment']->paid_at->equalTo($compensation->paid_at));
        $this->assertSame(RestoreLegacyCancelledPaidOrderAction::RESTORATION_REASON, $compensation->reference);
        $this->assertSame($cashMovementCount, CashMovement::query()->count());

        $this->assertCount(6, $newMovements);
        foreach ($newMovements as $movement) {
            $this->assertTrue((bool) data_get($movement->metadata, 'legacy_administrative_restore'));
            $this->assertSame($audit->children->sole()->id, data_get($movement->metadata, 'audit_id'));
            $this->assertContains(data_get($movement->metadata, 'original_movement_id'), $fixture['original_movement_ids']);
            $this->assertContains(data_get($movement->metadata, 'reversal_movement_id'), $fixture['reversal_movement_ids']);
            $this->assertNotEmpty(data_get($movement->metadata, 'batch_allocations'));
        }
        $this->assertSame($historicalMovements, InventoryMovement::query()->whereIn('id', array_keys($historicalMovements))
            ->get()->keyBy('id')->map->getAttributes()->all());
        $this->assertSame($reservationState, InventoryReservation::query()->whereIn('id', $fixture['reservation_ids'])
            ->get()->keyBy('id')->map->getAttributes()->all());
        $this->assertTrue(InventoryReservation::query()->whereIn('id', $fixture['reservation_ids'])
            ->get()->every(fn (InventoryReservation $reservation): bool => $reservation->status === InventoryReservationStatus::Consumed));

        $this->assertSame(OrderCancellationScope::PaidOrder, $audit->scope);
        $this->assertSame('legacy_administrative', data_get($audit->snapshot, 'recovery_type'));
        $this->assertTrue((bool) data_get($audit->snapshot, 'no_original_paid_order_audit'));
        $this->assertSame(RestoreLegacyCancelledPaidOrderAction::RESTORATION_REASON, $audit->restoration_reason);
        $this->assertSame($fixture['actor']->id, $audit->restored_by);
        $this->assertNotNull($audit->restored_at);
        $this->assertTrue($originalCancelledAt->equalTo($audit->cancelled_at));
        $child = $audit->children->sole();
        $this->assertSame(OrderCancellationScope::KitchenDispatchItem, $child->scope);
        $this->assertSame($item->id, $child->order_item_id);
        $this->assertSame($dispatch->id, $child->kitchen_dispatch_id);
        $this->assertSame(OrderItemStatus::Sent->value, data_get($child->snapshot, 'previous_status'));
        $this->assertTrue($originalCancelledAt->equalTo($child->cancelled_at));
    }

    public function test_second_recovery_and_preexisting_compensation_are_rejected(): void
    {
        $fixture = $this->fixture();
        $action = app(RestoreLegacyCancelledPaidOrderAction::class);
        $action->execute($fixture['order']->refresh(), $fixture['actor']);
        $counts = $this->databaseCounts();

        try {
            $action->execute($fixture['order']->refresh(), $fixture['actor']);
            $this->fail('Una segunda recuperación debía rechazarse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('anulación completo', $exception->getMessage());
        }
        $this->assertSame($counts, $this->databaseCounts());

        $other = $this->fixture();
        Payment::query()->create([
            'company_id' => $other['company']->id,
            'branch_id' => $other['branch']->id,
            'order_id' => $other['order']->id,
            'kitchen_dispatch_id' => $other['dispatch']->id,
            'cash_session_id' => $other['session']->id,
            'method' => PaymentMethod::Qr,
            'amount' => '99.00',
            'reference' => 'Duplicado previo',
            'paid_at' => now(),
            'received_by' => $other['actor']->id,
            'status' => PaymentStatus::Reversed,
            'idempotency_key' => 'legacy-restore-order-'.$other['order']->id.'-payment-'.$other['payment']->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pago compensatorio');
        $action->execute($other['order']->refresh(), $other['actor']);
    }

    public function test_stock_payment_and_inventory_inconsistencies_each_rollback_completely(): void
    {
        $stock = $this->fixture();
        $inventory = $stock['inventory_items']->first();
        app(ApplyInventoryMovementAction::class)->execute(
            $stock['company'],
            $stock['branch'],
            $inventory,
            InventoryMovementType::AdjustmentOut,
            '950.000',
            null,
            $stock['actor'],
            reason: 'Consumo posterior',
        );
        $before = $this->databaseCounts();
        try {
            app(RestoreLegacyCancelledPaidOrderAction::class)->execute($stock['order']->refresh(), $stock['actor']);
            $this->fail('El stock insuficiente debía abortar la recuperación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Stock insuficiente', $exception->getMessage());
        }
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame(OrderStatus::Cancelled, $stock['order']->refresh()->status);

        $payment = $this->fixture();
        $payment['reversal']->forceFill(['amount' => '98.00'])->save();
        $before = $this->databaseCounts();
        try {
            app(RestoreLegacyCancelledPaidOrderAction::class)->execute($payment['order']->refresh(), $payment['actor']);
            $this->fail('Un pago inconsistente debía abortar la recuperación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no coinciden', $exception->getMessage());
        }
        $this->assertSame($before, $this->databaseCounts());

        $inventoryCase = $this->fixture();
        $reversal = InventoryMovement::query()->findOrFail($inventoryCase['reversal_movement_ids'][0]);
        DB::table('inventory_movements')->where('id', $reversal->id)->update([
            'metadata' => json_encode([
                ...$reversal->metadata,
                'restored_batch_allocations' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        $before = $this->databaseCounts();
        try {
            app(RestoreLegacyCancelledPaidOrderAction::class)->execute($inventoryCase['order']->refresh(), $inventoryCase['actor']);
            $this->fail('Una reversión duplicada debía abortar la recuperación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('lotes restaurados', $exception->getMessage());
        }
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_permissions_company_isolation_and_reports_are_safe(): void
    {
        $fixture = $this->fixture();
        $cashier = User::factory()->create();
        Membership::factory()->for($fixture['company'])->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $admin = User::factory()->create();
        Membership::factory()->for($fixture['company'])->for($admin)->create(['role' => MembershipRole::Admin]);

        $this->assertTrue(app(RestoreLegacyCancelledPaidOrderAction::class)
            ->dryRun($fixture['order']->refresh(), $admin)['valid']);

        try {
            app(RestoreLegacyCancelledPaidOrderAction::class)->dryRun($fixture['order']->refresh(), $cashier);
            $this->fail('Cashier no debía poder validar una restauración sensible.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $otherCompany = Company::factory()->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        try {
            app(RestoreLegacyCancelledPaidOrderAction::class)->dryRun($fixture['order']->refresh(), $otherOwner);
            $this->fail('Un owner de otra empresa no debía poder validar la venta.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        app(RestoreLegacyCancelledPaidOrderAction::class)->execute($fixture['order']->refresh(), $fixture['actor']);
        $range = app(ReportDateRangeService::class)->from(['preset' => 'today']);
        $sales = app(SalesReportService::class)->data($fixture['company'], $fixture['branch'], $range);
        $profitability = app(ProfitabilityReportService::class)->data($fixture['company'], $fixture['branch'], $range);
        $qr = $sales['payments']->firstWhere('method', PaymentMethod::Qr->value);

        $this->assertSame(1, $sales['orders_paid']);
        $this->assertSame('99.00', $sales['sales_total']);
        $this->assertSame('99.00', $qr['amount']);
        $this->assertSame(1, $qr['count']);
        $this->assertSame('99.00', $profitability['sales']);
        $this->assertSame('2557.00', $profitability['production_cost']);
        $this->actingAs($fixture['actor'])
            ->withSession(['active_company_id' => $fixture['company']->id, 'active_branch_id' => $fixture['branch']->id])
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('ANULACIÓN REVERTIDA')
            ->assertSee(RestoreLegacyCancelledPaidOrderAction::RESTORATION_REASON);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $actor = User::factory()->create();
        Membership::factory()->for($company)->for($actor)->owner()->create();
        $unit = Unit::factory()->for($company)->create(['symbol' => 'g']);
        $product = Product::factory()->for($company)->create(['type' => ProductType::Pizza, 'name' => 'Pizza legacy']);
        $variant = ProductVariant::factory()->for($product)->create([
            'price' => '99.00',
            'requires_preparation' => true,
        ]);
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $actor);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $actor);
        $quantities = ['100.000', '180.000', '5.000', '18.000', '30.000', '310.000'];
        $inventoryItems = collect();
        foreach ($quantities as $index => $quantity) {
            $inventory = InventoryItem::factory()->for($unit)->create([
                'name' => 'Insumo legacy '.($index + 1),
            ]);
            app(ApplyInventoryMovementAction::class)->execute(
                $company,
                $branch,
                $inventory,
                InventoryMovementType::AdjustmentIn,
                '1000.000',
                (string) ($index + 1).'.000000',
                $actor,
                reason: 'Stock inicial',
            );
            InventoryReservation::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'inventory_item_id' => $inventory->id,
                'quantity' => $quantity,
                'status' => InventoryReservationStatus::Reserved,
                'reserved_at' => now(),
            ]);
            $inventoryItems->push($inventory);
        }

        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $actor);
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja legacy',
            'is_active' => true,
        ]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $actor);
        $payment = app(RegisterPaymentAction::class)->execute(
            $order->refresh(),
            $session,
            PaymentMethod::Qr,
            '99.00',
            $actor,
            'legacy-original-'.$order->id,
            reference: 'QR original',
            dispatch: $dispatch,
        );
        $operationalNumber = $order->refresh()->operational_number;
        app(CancelPaidOrderAction::class)->execute($order, $actor, 'Error legacy');
        $reversal = Payment::query()->where('reversal_of_id', $payment->id)->sole();
        $originalMovementIds = InventoryMovement::query()->where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', InventoryMovementType::OrderConsumption->value)
            ->orderBy('id')->pluck('id')->all();
        $reversalMovementIds = InventoryMovement::query()->whereIn('reversal_of_id', $originalMovementIds)
            ->orderBy('id')->pluck('id')->all();
        $reservationIds = InventoryReservation::query()->where('order_item_id', $item->id)->orderBy('id')->pluck('id')->all();

        OrderCancellationAudit::query()->where('order_id', $order->id)->whereNotNull('parent_id')->delete();
        OrderCancellationAudit::query()->where('order_id', $order->id)->delete();

        return [
            'company' => $company,
            'branch' => $branch,
            'actor' => $actor,
            'order' => $order->refresh(),
            'item' => $item->refresh(),
            'dispatch' => $dispatch->refresh(),
            'session' => $session->refresh(),
            'payment' => $payment->refresh(),
            'reversal' => $reversal->refresh(),
            'inventory_items' => $inventoryItems,
            'original_movement_ids' => $originalMovementIds,
            'reversal_movement_ids' => $reversalMovementIds,
            'reservation_ids' => $reservationIds,
            'operational_number' => $operationalNumber,
        ];
    }

    /** @return array<string, int> */
    private function databaseCounts(): array
    {
        return [
            'audits' => OrderCancellationAudit::query()->count(),
            'payments' => Payment::query()->count(),
            'movements' => InventoryMovement::query()->count(),
            'reservations' => InventoryReservation::query()->count(),
            'cash_movements' => CashMovement::query()->count(),
        ];
    }
}
