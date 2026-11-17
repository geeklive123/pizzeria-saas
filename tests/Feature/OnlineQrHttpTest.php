<?php

namespace Tests\Feature;

use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\PrinterPurpose;
use App\Enums\ProductType;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\OnlineOrderActionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SimulatedOnlineBank;
use Tests\TestCase;

class OnlineQrHttpTest extends TestCase
{
    public static function resolutionTypes(): array
    {
        return [['refund', 'Devolución interna documentada'], ['accounting', 'Regularización contable documentada'], ['invalid_reservation', ''], ['cancel', ''], ['cancel_expired', '']];
    }

    #[DataProvider('resolutionTypes')]
    public function test_http_delivery_generates_and_reuses_a_simulated_bank_qr(string $resolutionType, string $resolutionLabel): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('La prueba requiere SQLite en memoria.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $category = Category::factory()->for($company)->create();
        $product = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
        $cashier = User::factory()->create();
        Membership::factory()->for($company)->for($cashier)->owner()->create();
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Prueba', 'is_active' => true]);
        $cash = CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id,
            'active_cash_register_id' => $register->id, 'opened_by' => $cashier->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
        $unit = Unit::factory()->for($company)->create();
        $inventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
            'product_variant_id' => $variant->id, 'name' => 'Stock de prueba', 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
            '2.000', '1.000000', $cashier, reason: 'Prueba HTTP');
        config(['storefront.default' => ['company_id' => $company->id, 'branch_id' => $branch->id],
            'storefront.visibility' => ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []],
            'storefront.demo_mode' => false, 'storefront.delivery_payment_preparation_enabled' => true,
            'storefront.online_dynamic_qr_enabled' => true, 'baneco.enabled' => true,
            'storefront.delivery.branches' => [$company->id.':'.$branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]]);
        BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
            'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
        $bank = new SimulatedOnlineBank;
        $bank->install();
        $key = (string) Str::uuid();
        $input = ['idempotency_key' => $key, 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
            'address' => 'Calle', 'reference' => 'Portón', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
            'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]];
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk();
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $key])->assertOk()->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', '24.00')->assertJsonPath('qr_image', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $key])->assertOk()->assertJsonPath('status', 'pending');
        $operation = DB::table('online_qr_operations')->sole();
        $intent = DB::table('dynamic_qr_intents')->sole();
        $this->assertEquals($intent->id, $operation->dynamic_qr_intent_id);
        $this->assertEquals($cash->id, $intent->cash_session_id);
        $this->assertEquals($operation->pos_order_id, $intent->order_id);
        $this->assertSame('bank-fake-1', $intent->bank_qr_id);
        $this->assertSame(1, $bank->generations);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('print_attempts', 0);
        // A terminal-looking QR must not allow the ordinary POS cancellation
        // to bypass the online administrative disposition.
        DB::table('dynamic_qr_intents')->where('id', $intent->id)->update(['status' => 'cancelled', 'active_order_id' => null, 'bank_status' => '9']);
        try {
            app(CancelOrderAction::class)->execute(Order::findOrFail($operation->pos_order_id), $cashier, 'Cancelación de prueba');
            $this->fail('La cancelación POS no debe disponer de una reserva QR online.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('disposición administrativa', $exception->getMessage());
        }
        $this->assertSame('reserved', DB::table('inventory_reservations')->where('order_id', $operation->pos_order_id)->value('status'));
        DB::table('dynamic_qr_intents')->where('id', $intent->id)->update(['status' => $intent->status, 'active_order_id' => $intent->active_order_id, 'bank_status' => $intent->bank_status]);
        $this->assertSame(0, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
        if (in_array($resolutionType, ['cancel', 'cancel_expired'], true)) {
            $requestId = DB::table('online_order_requests')->where('idempotency_key', $key)->value('id');
            $url = route('online-orders.qr-disposition', $requestId);
            $inputDisposition = ['disposition' => 'cancel', 'amount' => '24.00', 'action_date' => now()->toDateString(),
                'reason' => 'Cancelación revisada', 'evidence' => 'Confirmación bancaria caso 123', 'confirmed' => '1'];
            $otherUser = User::factory()->create();
            Membership::factory()->for($company)->for($otherUser)->create(['role' => 'cashier']);
            $this->actingAs($otherUser)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
            $this->post($url, $inputDisposition)->assertForbidden();
            $this->actingAs($cashier);
            $otherBranch = Branch::factory()->for($company)->create();
            $this->withSession(['active_branch_id' => $otherBranch->id])->post($url, $inputDisposition)->assertNotFound();
            $this->withSession(['active_branch_id' => $branch->id]);
            $otherCompany = Company::factory()->create();
            $foreignBranch = Branch::factory()->for($otherCompany)->create();
            $foreignOwner = User::factory()->create();
            Membership::factory()->for($otherCompany)->for($foreignOwner)->owner()->create();
            $this->actingAs($foreignOwner)->withSession(['active_company_id' => $otherCompany->id, 'active_branch_id' => $foreignBranch->id]);
            $this->post($url, $inputDisposition)->assertNotFound();
            $this->actingAs($cashier)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
            $this->post($url, $inputDisposition)->assertSessionHasErrors('disposition');
            $this->assertSame('reserved', DB::table('inventory_reservations')->where('order_id', $operation->pos_order_id)->value('status'));
            if ($resolutionType === 'cancel_expired') {
                DB::table('dynamic_qr_intents')->where('id', $intent->id)->update(['status' => 'reconciliation_required', 'issue_code' => 'expiry_unconfirmed', 'expires_at' => now()->subMinute()]);
            }
            $bank->bankCancelled = true;
            Cache::flush();
            $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $key])->assertOk();
            $this->assertSame('9', DB::table('dynamic_qr_intents')->where('id', $intent->id)->value('bank_status'));
            $bankLock = Cache::lock('baneco:intent:'.$intent->id, 120);
            $this->assertTrue($bankLock->get());
            $this->post($url, $inputDisposition)->assertSessionHasErrors('disposition');
            $bankLock->release();
            $this->post($url, array_merge($inputDisposition, ['evidence' => '']))->assertSessionHasErrors('evidence');
            $this->get(route('online-orders.show', $requestId))->assertOk()->assertDontSee('Cancelar y liberar reservas')
                ->assertSee('confirmación bancaria de no pagabilidad definitiva');
            $admin = User::factory()->create();
            Membership::factory()->for($company)->for($admin)->create(['role' => 'admin']);
            $this->actingAs($admin);
            $this->post($url, $inputDisposition)->assertSessionHasErrors('disposition');
            $this->post($url, $inputDisposition)->assertSessionHasErrors('disposition');
            $this->assertDatabaseCount('online_qr_dispositions', 0);
            $this->assertSame('reserved', DB::table('inventory_reservations')->where('order_id', $operation->pos_order_id)->value('status'));
            $this->assertNotSame('cancelled', DB::table('orders')->where('id', $operation->pos_order_id)->value('status'));
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(0, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
            if ($resolutionType === 'cancel') {
                // A later bank confirmation after status 9 is evidence for reconciliation,
                // never authority to release the reservation or create a POS payment.
                $bank->bankCancelled = false;
                $bank->paidQr = true;
                DB::table('dynamic_qr_intents')->where('id', $intent->id)->update(['last_checked_at' => null]);
                $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $key])
                    ->assertOk()->assertJsonPath('status', 'reconciliation_required');
                $this->assertSame('late_payment', DB::table('dynamic_qr_intents')->where('id', $intent->id)->value('issue_code'));
                $this->assertSame('reserved', DB::table('inventory_reservations')->where('order_id', $operation->pos_order_id)->value('status'));
                $this->assertDatabaseCount('payments', 0);
            }

            return;
        }
        $otherBranch = Branch::factory()->for($company)->create();
        config()->set('storefront.default.branch_id', $otherBranch->id);
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $key])->assertNotFound();
        config()->set('storefront.default.branch_id', $branch->id);
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
            '2.000', '1.000000', $cashier, reason: 'Prueba de respuesta incierta');
        $bank->timeout = true;
        $input['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk();
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $input['idempotency_key']])
            ->assertOk()->assertJsonPath('status', 'generation_unknown');
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $input['idempotency_key']])
            ->assertOk()->assertJsonPath('status', 'generation_unknown');
        $this->assertSame(2, $bank->generations);
        $this->assertDatabaseCount('dynamic_qr_intents', 2);
        $this->assertDatabaseCount('payments', 0);
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
            '2.000', '1.000000', $cashier, reason: 'Prueba de fallo previo');
        Cache::flush();
        $bank->authFail = true;
        $input['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk();
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $input['idempotency_key']])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame(2, $bank->generations);
        $this->assertDatabaseCount('payments', 0);
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
            '2.000', '1.000000', $cashier, reason: 'Prueba de cierre');
        $input['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk();
        foreach ([PrinterPurpose::Kitchen, PrinterPurpose::CustomerTicket] as $purpose) {
            PrinterSetting::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
                'purpose' => $purpose, 'windows_printer_name' => 'Simulated printer', 'is_active' => true,
                'auto_print' => true, 'copies' => 1, 'paper_width_mm' => 80]);
        }
        DB::table('online_order_requests')->where('idempotency_key', $key)->update(['preparation_expires_at' => now()->subMinute()]);
        $bank->authFail = false;
        $bank->paidQr = true;
        Cache::flush();
        if ($resolutionType === 'invalid_reservation') {
            $forged = DynamicQrIntent::query()->findOrFail($intent->id);
            $forged->bank_status = '1';
            $forged->reconciliation_data = ['qrId' => $forged->bank_qr_id, 'currency' => 'BOB', 'amount' => '24.00'];
            try {
                DB::transaction(fn () => OnlineOrderActionContext::fromConfirmedIntent($forged,
                    Order::query()->findOrFail($operation->pos_order_id), $cash->fresh(), User::query()->findOrFail($operation->system_user_id)));
                $this->fail('Una confirmación solo en memoria no debe autorizar el contexto online.');
            } catch (\DomainException) {
                $this->assertNotSame('1', DB::table('dynamic_qr_intents')->where('id', $intent->id)->value('bank_status'));
            }
            DB::transaction(function () use ($forged, $operation, $cash): void {
                $forged->save();
                $order = Order::query()->findOrFail($operation->pos_order_id);
                $actor = User::query()->findOrFail($operation->system_user_id);
                $context = OnlineOrderActionContext::fromConfirmedIntent($forged, $order, $cash->fresh(), $actor);
                $context->assertConfirmedPayment($order, $cash, $actor, $forged);
                $forged->update(['status' => 'reconciliation_required']);
                try {
                    OnlineOrderActionContext::fromConfirmedIntent($forged, $order, $cash, $actor);
                    $this->fail('Una incidencia abierta no debe autorizar consumo ni pago automático.');
                } catch (\DomainException) {
                    $this->assertSame('reconciliation_required', $forged->fresh()->status->value);
                }
                try {
                    $context->assertConfirmedOrder($order, $actor);
                    $this->fail('Un contexto previo no debe autorizar consumo después de abrirse una incidencia.');
                } catch (\DomainException) {
                    $this->assertTrue(true);
                } finally {
                    $forged->update(['status' => 'pending']);
                }
                DB::table('cash_sessions')->where('id', $cash->id)->update(['status' => 'closed']);
                try {
                    OnlineOrderActionContext::fromConfirmedIntent($forged, $order, $cash, $actor);
                    $this->fail('Una caja cerrada no debe aceptarse mediante un modelo desactualizado.');
                } catch (\DomainException) {
                    $this->assertSame('closed', DB::table('cash_sessions')->where('id', $cash->id)->value('status'));
                } finally {
                    DB::table('cash_sessions')->where('id', $cash->id)->update(['status' => 'open']);
                }
                $otherIntent = clone $forged;
                $otherIntent->id += 1000;
                $otherOrder = clone $order;
                $otherOrder->id += 1000;
                $otherCompany = clone $order;
                $otherCompany->company_id += 1000;
                $otherBranch = clone $order;
                $otherBranch->branch_id += 1000;
                $otherCash = clone $cash;
                $otherCash->id += 1000;
                foreach ([
                    fn () => $context->assertConfirmedPayment($order, $cash, $actor, $otherIntent),
                    fn () => $context->assertConfirmedPayment($otherOrder, $cash, $actor, $forged),
                    fn () => $context->assertConfirmedPayment($otherCompany, $cash, $actor, $forged),
                    fn () => $context->assertConfirmedPayment($otherBranch, $cash, $actor, $forged),
                    fn () => $context->assertConfirmedPayment($order, $otherCash, $actor, $forged),
                    fn () => $context->assertConfirmedPayment($order, $cash, $cash->openedBy, $forged),
                ] as $attempt) {
                    try {
                        $attempt();
                        $this->fail('El contexto confirmado no debe autorizar otro pago, orden, ámbito, caja o actor.');
                    } catch (\DomainException) {
                        $this->assertTrue(true);
                    }
                }
                $forged->forceFill(['bank_status' => null, 'reconciliation_data' => null])->save();
            });
            DB::table('inventory_reservations')->where('order_id', $operation->pos_order_id)->update(['quantity' => '1.000']);
            $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $key])
                ->assertOk()->assertJsonPath('status', 'reconciliation_required');
            $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $key])
                ->assertOk()->assertJsonPath('status', 'reconciliation_required');
            $failedIntent = DB::table('dynamic_qr_intents')->where('id', $operation->dynamic_qr_intent_id)->first();
            $this->assertSame('payment_context_rejected', $failedIntent->issue_code);
            $this->assertSame('simulated-transaction', json_decode($failedIntent->reconciliation_data, true, flags: JSON_THROW_ON_ERROR)['transactionId']);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('print_attempts', 0);
            $this->assertSame(0, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
            $reservation = DB::table('inventory_reservations')->where('order_id', $operation->pos_order_id)->sole();
            $this->assertSame('reserved', $reservation->status);
            $this->assertEquals('1.000', $reservation->quantity);

            return;
        }
        $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $key])->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $key])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('paid', DB::table('online_order_requests')->where('idempotency_key', $key)->value('status'));
        $this->assertSame('paid', DB::table('orders')->where('id', $operation->pos_order_id)->value('status'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('24.00', app(CashSessionSummaryService::class)->calculate($cash->fresh())['qr_payments']);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $this->assertSame(2, DB::table('print_attempts')->count());
        $bank->timeout = false;
        $lateKey = $input['idempotency_key'];
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $lateKey])->assertOk()->assertJsonPath('status', 'pending');
        $bank->paidBankId = 'bank-fake-3';
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
            '2.000', '1.000000', $cashier, reason: 'Preparación para caja cerrada');
        $input['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk();
        $cash->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $this->postJson(route('storefront.preparation.qr'), ['idempotency_key' => $input['idempotency_key']])->assertUnprocessable();
        $this->postJson(route('storefront.preparation.qr.status'), ['idempotency_key' => $lateKey])->assertOk()->assertJsonPath('status', 'reconciliation_required');
        $this->assertSame('cash_session_closed', DB::table('dynamic_qr_intents')->where('bank_qr_id', $bank->paidBankId)->value('issue_code'));
        $lateEvidence = json_decode(DB::table('dynamic_qr_intents')->where('bank_qr_id', $bank->paidBankId)->value('reconciliation_data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('simulated-transaction', $lateEvidence['transactionId']);
        $this->assertSame(3, $bank->generations);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $this->assertDatabaseCount('print_attempts', 2);
        $this->actingAs($cashier)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $paidRequestId = DB::table('online_order_requests')->where('idempotency_key', $key)->value('id');
        $this->get(route('online-orders.index', ['status' => 'paid']))->assertOk()->assertSee('PAGADO')->assertSee('Cliente');
        $this->get(route('online-orders.show', $paidRequestId))->assertOk()->assertSee('PAGADO')->assertSee('bank-fake-1')
            ->assertSee('Reimprimir comanda')->assertSee('Reimprimir ticket')
            ->assertSee('Pago registrado. No hay una incidencia bancaria pendiente.');
        $lateRequestId = DB::table('online_order_requests')->where('idempotency_key', $lateKey)->value('id');
        $url = route('online-orders.closed-payment-resolution', $lateRequestId);
        $resolution = ['resolution' => $resolutionType, 'reason' => 'Acción tras revisión', 'evidence' => 'Comprobante interno 123', 'confirmed' => '1'];
        $ordinaryUser = User::factory()->create();
        Membership::factory()->for($company)->for($ordinaryUser)->create(['role' => 'cashier']);
        $this->actingAs($ordinaryUser)->post($url, $resolution)->assertForbidden();
        $this->actingAs($cashier);
        $this->get(route('online-orders.show', $lateRequestId))->assertOk()->assertSee('Registrar resolución');
        $this->post($url, ['resolution' => 'refund'])->assertSessionHasErrors(['reason', 'evidence', 'confirmed']);
        DB::table('cash_sessions')->where('id', $cash->id)->update(['status' => 'open']);
        $this->post($url, $resolution)->assertSessionHasErrors('resolution');
        DB::table('cash_sessions')->where('id', $cash->id)->update(['status' => 'closed']);
        $intentQuery = DB::table('dynamic_qr_intents')->where('bank_qr_id', $bank->paidBankId);
        $intentQuery->update(['reconciliation_data' => json_encode(array_merge($lateEvidence, ['amount' => '1.00']))]);
        $this->post($url, $resolution)->assertSessionHasErrors('resolution');
        $intentQuery->update(['reconciliation_data' => json_encode($lateEvidence)]);
        $this->post($url, $resolution)->assertRedirect(route('online-orders.show', $lateRequestId));
        $this->assertDatabaseCount('online_qr_resolutions', 1);
        $disposition = ['disposition' => $resolutionType, 'amount' => '24.00', 'action_date' => now()->toDateString(),
            'reason' => 'Resolución documentada', 'evidence' => 'Comprobante interno 123', 'confirmed' => '1'];
        $this->post(route('online-orders.qr-disposition', $lateRequestId), $disposition)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('online-orders.qr-disposition', $lateRequestId), $disposition)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('online_qr_dispositions', 1);
        $dispositionId = DB::table('online_qr_dispositions')->value('id');
        foreach (['update', 'delete'] as $attempt) {
            try {
                $row = DB::table('online_qr_dispositions')->where('id', $dispositionId);
                $attempt === 'update' ? $row->update(['order_result' => 'alterado']) : $row->delete();
                $this->fail('La auditoría no debe poder modificarse ni eliminarse.');
            } catch (QueryException) {
                $this->assertDatabaseHas('online_qr_dispositions', ['id' => $dispositionId, 'order_result' => 'retained']);
            }
        }
        $this->assertSame('reserved', DB::table('inventory_reservations')->where('order_id', DB::table('online_qr_operations')->where('online_order_request_id', $lateRequestId)->value('pos_order_id'))->value('status'));
        $this->get(route('online-orders.show', $lateRequestId))->assertOk()->assertSee('Comprobante interno 123')->assertSee($resolutionLabel);
        $deliveryUrl = route('online-orders.qr-delivery', $lateRequestId);
        $delivery = ['action_date' => now()->toDateString(), 'reason' => 'Entrega física verificada',
            'evidence' => 'Acta de entrega simulada 123', 'confirmed' => '1'];
        $this->actingAs($ordinaryUser)->post($deliveryUrl, $delivery)->assertForbidden();
        $this->actingAs($cashier)->post($deliveryUrl, ['action_date' => now()->toDateString()])->assertSessionHasErrors(['reason', 'evidence', 'confirmed']);
        $lateOrderId = DB::table('online_qr_operations')->where('online_order_request_id', $lateRequestId)->value('pos_order_id');
        $this->assertSame('reserved', DB::table('inventory_reservations')->where('order_id', $lateOrderId)->value('status'));
        $lateReservation = DB::table('inventory_reservations')->where('order_id', $lateOrderId)->sole();
        DB::table('inventory_reservations')->where('id', $lateReservation->id)->update(['quantity' => '1.000']);
        $this->post($deliveryUrl, $delivery)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('online_qr_inventory_outcomes', 0);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
        DB::table('inventory_reservations')->where('id', $lateReservation->id)->update(['quantity' => $lateReservation->quantity]);
        $this->post($deliveryUrl, $delivery)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($deliveryUrl, $delivery)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($deliveryUrl, array_merge($delivery, ['reason' => 'Otra entrega distinta']))->assertSessionHasErrors('inventory_outcome');
        $this->assertDatabaseCount('online_qr_inventory_outcomes', 1);
        $outcomeId = DB::table('online_qr_inventory_outcomes')->value('id');
        foreach (['update', 'delete'] as $attempt) {
            try {
                $row = DB::table('online_qr_inventory_outcomes')->where('id', $outcomeId);
                $attempt === 'update' ? $row->update(['outcome' => 'alterado']) : $row->delete();
                $this->fail('El resultado de inventario debe ser inmutable.');
            } catch (QueryException) {
                $this->assertDatabaseHas('online_qr_inventory_outcomes', ['id' => $outcomeId, 'outcome' => 'delivered']);
            }
        }
        $this->assertSame('consumed', DB::table('inventory_reservations')->where('order_id', $lateOrderId)->value('status'));
        $lateItemId = DB::table('order_items')->where('order_id', $lateOrderId)->value('id');
        $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->where('reference_id', $lateItemId)->count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('closed', DB::table('cash_sessions')->where('id', $cash->id)->value('status'));
        $this->assertSame($cash->id, DB::table('dynamic_qr_intents')->where('bank_qr_id', $bank->paidBankId)->value('cash_session_id'));
        $this->get(route('online-orders.show', $lateRequestId))->assertOk()->assertSee('Entrega documentada por');
        $this->post($url, $resolution)->assertSessionHasErrors('resolution');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('cash_session_closed', DB::table('dynamic_qr_intents')->where('bank_qr_id', $bank->paidBankId)->value('issue_code'));
    }
}
