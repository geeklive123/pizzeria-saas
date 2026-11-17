<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\OnlineOrderActionContext;
use App\Services\OnlinePaymentPreparationService;
use App\Services\PrepareOnlineQrOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OnlinePaymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private ProductVariant $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->for($this->company)->create();
        $category = Category::factory()->for($this->company)->create();
        $product = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
        $this->variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
        $this->owner = User::factory()->create();
        Membership::factory()->for($this->company)->for($this->owner)->owner()->create();
        config()->set('storefront.default', ['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
        config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []]);
        config()->set('storefront.demo_mode', false);
        config()->set('storefront.delivery_payment_preparation_enabled', false);
        config()->set('storefront.online_pos_conversion_enabled', true);
        config()->set('storefront.delivery.branches', [$this->company->id.':'.$this->branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
    }

    private function input(): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente prueba', 'customer_phone' => '70000000', 'fulfillment' => 'delivery',
            'address' => 'Calle de prueba', 'reference' => 'Portón', 'latitude' => -17.3769946, 'longitude' => -66.1771762, 'location_confirmed' => true,
            'items' => [['variant_ulid' => $this->variant->ulid, 'quantity' => 2]]];
    }

    private function openCash(): CashSession
    {
        $register = CashRegister::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Prueba '.Str::random(8), 'is_active' => true]);

        return CashSession::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'cash_register_id' => $register->id,
            'active_cash_register_id' => $register->id, 'opened_by' => $this->owner->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
    }

    public function test_flag_is_off_and_pickup_remains_available(): void
    {
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertNotFound();
        $data['fulfillment'] = 'pickup';
        $this->postJson(route('storefront.checkout.store'), $data)->assertOk();
    }

    public function test_availability_tracks_cash_opening_and_closing_for_the_branch(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $url = route('storefront.preparation.availability');
        $this->getJson($url)->assertOk()->assertJsonPath('open', false)->assertHeader('Cache-Control', 'no-store, private');
        $session = $this->openCash();
        $this->getJson($url)->assertOk()->assertJsonPath('open', true);
        $otherBranch = Branch::factory()->for($this->company)->create();
        config()->set('storefront.default.branch_id', $otherBranch->id);
        $this->getJson($url)->assertOk()->assertJsonPath('open', false);
        config()->set('storefront.default.branch_id', $this->branch->id);
        $session->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('open', false);
    }

    public function test_local_handoff_runs_under_the_original_cash_and_preparation_locks(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $session = $this->openCash();
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $preparation = OnlineOrderRequest::query()->sole();
        $result = app(OnlinePaymentPreparationService::class)->withUsable($this->company, $this->branch, $preparation,
            function (OnlineOrderRequest $locked, CashSession $cash) use ($preparation, $session): string {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertSame($preparation->id, $locked->id);
                $this->assertSame($session->id, $cash->id);
                $locked->update(['notes' => 'Vínculo local de prueba']);

                return 'ok';
            });
        $this->assertSame('ok', $result);
        $this->assertSame('Vínculo local de prueba', $preparation->fresh()->notes);
        try {
            app(OnlinePaymentPreparationService::class)->withUsable($this->company, $this->branch, $preparation,
                function (OnlineOrderRequest $locked): void {
                    $locked->update(['notes' => 'No debe persistir']);
                    throw ValidationException::withMessages(['handoff' => 'Error local simulado']);
                });
            $this->fail('Se esperaba un error local simulado.');
        } catch (ValidationException $exception) {
            $this->assertSame('Error local simulado', $exception->errors()['handoff'][0]);
        }
        $this->assertSame('Vínculo local de prueba', $preparation->fresh()->notes);
        $this->assertSame('payment_preparation', $preparation->fresh()->status);
        $session->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $called = false;
        try {
            app(OnlinePaymentPreparationService::class)->withUsable($this->company, $this->branch, $preparation,
                function () use (&$called): void {
                    $called = true;
                });
            $this->fail('La caja cerrada debe bloquear el vínculo.');
        } catch (ValidationException) {
            $this->assertFalse($called);
        }
        $this->assertSame('preparation_invalidated', $preparation->fresh()->status);
    }

    public function test_handoff_rejects_a_preparation_whose_cash_session_changed_after_reading(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $this->openCash();
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $stale = OnlineOrderRequest::query()->sole();
        $otherSession = $this->openCash();
        OnlineOrderRequest::query()->whereKey($stale->id)->update(['preparation_cash_session_id' => $otherSession->id]);
        $called = false;
        try {
            app(OnlinePaymentPreparationService::class)->withUsable($this->company, $this->branch, $stale,
                function () use (&$called): void {
                    $called = true;
                });
            $this->fail('Una caja cambiada debe rechazar el vínculo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_session', $exception->errors());
        }
        $this->assertFalse($called);
        $this->assertSame('preparation_invalidated', $stale->fresh()->status);
    }

    public function test_online_operation_preserves_original_cashier_and_is_idempotent_without_financial_effects(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $cash = $this->openCash();
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $preparation = OnlineOrderRequest::query()->sole();
        $service = app(PrepareOnlineQrOperation::class);
        $first = $service->execute($this->company, $this->branch, $preparation);
        $second = $service->execute($this->company, $this->branch, $preparation->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending_emission', $first->status);
        $this->assertSame($cash->id, $first->cash_session_id);
        $this->assertSame($this->owner->id, $first->original_cashier_id);
        $this->assertNotSame($this->owner->id, $first->system_user_id);
        $this->assertSame('Sistema — Tienda online', User::query()->findOrFail($first->system_user_id)->name);
        $result = $service->withPendingOperation($this->company, $this->branch, $preparation->fresh(),
            function (OnlineOrderRequest $locked, CashSession $session, User $actor, object $context) use ($first, $cash): string {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertSame($cash->id, $session->id);
                $this->assertSame($first->id, $context->id);
                $this->assertSame($first->system_user_id, $actor->id);

                return 'verificado';
            });
        $this->assertSame('verificado', $result);
        $this->assertDatabaseCount('online_qr_operations', 1);
        foreach (['orders', 'payments', 'cash_movements', 'inventory_movements', 'dynamic_qr_intents', 'print_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_online_operation_cannot_be_used_with_a_forged_cashier_snapshot(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $this->openCash();
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $preparation = OnlineOrderRequest::query()->sole();
        $service = app(PrepareOnlineQrOperation::class);
        $operation = $service->execute($this->company, $this->branch, $preparation);
        $other = User::factory()->create();
        DB::table('online_qr_operations')->where('id', $operation->id)->update(['original_cashier_id' => $other->id]);
        $called = false;
        try {
            $service->withPendingOperation($this->company, $this->branch, $preparation,
                function () use (&$called): void {
                    $called = true;
                });
            $this->fail('No debe autorizarse un contexto con titular de caja alterado.');
        } catch (\DomainException) {
            $this->assertFalse($called);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('dynamic_qr_intents', 0);
        }
    }

    public function test_online_operation_rejects_a_closed_original_cash_session(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $cash = $this->openCash();
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $preparation = OnlineOrderRequest::query()->sole();
        $cash->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        try {
            app(PrepareOnlineQrOperation::class)->execute($this->company, $this->branch, $preparation);
            $this->fail('No debe prepararse una emisión con caja cerrada.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('online_qr_operations', 0);
            $this->assertDatabaseCount('dynamic_qr_intents', 0);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_open_cash_prepares_idempotently_without_financial_effects_or_pos_conversion(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $session = $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk()->assertJsonPath('ready', true)->assertJsonPath('subtotal', '24.00');
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', true);
        $this->postJson(route('storefront.checkout.recover'), ['idempotency_key' => $data['idempotency_key']])->assertNotFound();
        $this->postJson(route('storefront.checkout.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertNotFound();
        $row = OnlineOrderRequest::query()->sole();
        $this->assertSame('payment_preparation', $row->status);
        $this->assertSame($session->id, $row->preparation_cash_session_id);
        $this->assertDatabaseCount('online_order_requests', 1);
        foreach (['orders', 'payments', 'cash_movements', 'inventory_movements', 'kitchen_dispatches', 'dynamic_qr_intents', 'print_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->actingAs($this->owner)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
        $this->post(route('online-orders.transition', $row->id), ['status' => 'confirmed'])->assertSessionHas('online_order_notice');
        $this->assertNull($row->fresh()->pos_order_id);
        $data['items'][0]['quantity'] = 1;
        $this->postJson(route('storefront.preparation.store'), $data)->assertUnprocessable();
    }

    public function test_closed_cash_and_outside_coverage_fail_closed(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('cash_session')
            ->assertJsonPath('errors.cash_session.0', 'Estamos fuera de horario. Por el momento no podemos recibir pedidos online. Vuelve a intentarlo cuando el restaurante esté atendiendo.');
        $this->openCash();
        $data['latitude'] = -17.4;
        $this->postJson(route('storefront.preparation.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('location_confirmed');
        $this->assertDatabaseCount('online_order_requests', 0);
    }

    public function test_unconfigured_origin_fails_without_creating_a_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        config()->set('storefront.delivery.branches', []);
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertUnprocessable()->assertJsonValidationErrors('location_confirmed');
        $this->assertDatabaseCount('online_order_requests', 0);
    }

    public function test_closing_the_associated_cash_session_invalidates_an_existing_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $session = $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $session->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])
            ->assertOk()->assertJsonPath('ready', false)
            ->assertJsonPath('message', 'Estamos fuera de horario. Por el momento no podemos recibir pedidos online. Vuelve a intentarlo cuando el restaurante esté atendiendo.');
        $this->assertSame('preparation_invalidated', OnlineOrderRequest::query()->sole()->status);
    }

    public function test_close_price_change_expiry_and_cancellation_cannot_reuse_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $session = $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $this->variant->update(['price' => '13.00']);
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $this->variant->update(['price' => '12.00']);
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $session->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $this->postJson(route('storefront.preparation.cancel'), ['idempotency_key' => $data['idempotency_key']])->assertOk();
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_expiry_and_a_new_cash_session_do_not_reactivate_the_old_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $session = $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $this->travel(16)->minutes();
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $this->travelBack();
        $session->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $this->openCash();
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $this->assertSame('preparation_invalidated', OnlineOrderRequest::query()->sole()->status);
    }

    public function test_reopening_cash_requires_a_new_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $session = $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $session->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $newSession = $this->openCash();
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('ready', false);
        $data['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk()->assertJsonPath('ready', true);
        $this->assertSame($newSession->id, OnlineOrderRequest::query()->where('idempotency_key', $data['idempotency_key'])->sole()->preparation_cash_session_id);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_other_branch_cash_and_unknown_tokens_cannot_access_a_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $otherBranch = Branch::factory()->for($this->company)->create();
        config()->set('storefront.default.branch_id', $otherBranch->id);
        config()->set('storefront.delivery.branches.'.$this->company->id.':'.$otherBranch->id, ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]);
        $this->postJson(route('storefront.preparation.store'), array_replace($data, ['idempotency_key' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('cash_session');
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unavailable_stock_cannot_create_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $this->openCash();
        $unit = Unit::factory()->for($this->company)->create();
        InventoryItem::query()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id,
            'product_variant_id' => $this->variant->id, 'name' => 'Producto sin stock', 'is_active' => true]);
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('online_order_requests', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_preparation_cannot_ignore_another_orders_reservation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $this->openCash();
        $unit = Unit::factory()->for($this->company)->create();
        $inventory = InventoryItem::query()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id,
            'product_variant_id' => $this->variant->id, 'name' => 'Producto reservado', 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($this->company, $this->branch, $inventory,
            InventoryMovementType::AdjustmentIn, '2.000', '1.000000', $this->owner, reason: 'Prueba aislada');
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $preparation = OnlineOrderRequest::query()->sole();
        $otherOrder = app(CreateTakeawayOrderAction::class)->execute($this->company, $this->branch, $this->owner);
        app(AddOrderItemAction::class)->execute($otherOrder, $this->variant, 2, $this->owner);
        try {
            app(OnlinePaymentPreparationService::class)->assertUsable($this->company, $this->branch, $preparation);
            $this->fail('La reserva de otro pedido no debe tratarse como stock disponible.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertSame('preparation_invalidated', $preparation->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
        $this->assertSame(0, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
    }

    /** Construct the future linked state using the existing POS reservation action. */
    private function preparationWithOwnReservation(): OnlineOrderRequest
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $this->openCash();
        $unit = Unit::factory()->for($this->company)->create();
        $inventory = InventoryItem::query()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id,
            'product_variant_id' => $this->variant->id, 'name' => 'Últimas unidades', 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($this->company, $this->branch, $inventory,
            InventoryMovementType::AdjustmentIn, '2.000', '1.000000', $this->owner, reason: 'Prueba aislada');
        $this->postJson(route('storefront.preparation.store'), $this->input())->assertOk();
        $preparation = OnlineOrderRequest::query()->sole();
        $operation = app(PrepareOnlineQrOperation::class)->execute($this->company, $this->branch, $preparation);
        $order = app(CreateTakeawayOrderAction::class)->execute($this->company, $this->branch, $this->owner);
        $item = app(AddOrderItemAction::class)->execute($order, $this->variant, 2, $this->owner);
        // The online order creator is not implemented yet; construct its expected audited linkage.
        $order->update(['created_by' => $operation->system_user_id]);
        $item->update(['created_by' => $operation->system_user_id]);
        DB::table('online_qr_operations')->where('id', $operation->id)->update(['pos_order_id' => $order->id]);
        $preparation->update(['pos_order_id' => $order->id]);

        return $preparation->fresh();
    }

    public function test_own_reservation_is_reusable_without_reserving_twice(): void
    {
        $preparation = $this->preparationWithOwnReservation();
        $service = app(OnlinePaymentPreparationService::class);
        $service->assertUsable($this->company, $this->branch, $preparation);
        $service->assertUsable($this->company, $this->branch, $preparation->fresh());
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertEquals(2, DB::table('inventory_reservations')->value('quantity'));
        $this->assertSame('payment_preparation', $preparation->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
    }

    public function test_linked_online_context_rejects_foreign_orders_actors_scopes_cash_and_unconfirmed_payments(): void
    {
        $request = $this->preparationWithOwnReservation();
        $operation = DB::table('online_qr_operations')->where('online_order_request_id', $request->id)->sole();
        $cash = CashSession::query()->findOrFail($operation->cash_session_id);
        $actor = User::query()->findOrFail($operation->system_user_id);
        $order = $request->posOrder()->firstOrFail();
        DB::transaction(function () use ($request, $operation, $cash, $actor, $order): void {
            $context = OnlineOrderActionContext::fromLinkedOperation($request, $cash, $actor, $operation, $order);
            $context->assertOrder($order, $actor);
            DB::table('cash_sessions')->where('id', $cash->id)->update(['status' => 'closed']);
            try {
                OnlineOrderActionContext::fromLinkedOperation($request, $cash, $actor, $operation, $order);
                $this->fail('Una caja cerrada no puede autorizar el contexto vinculado mediante un modelo desactualizado.');
            } catch (\DomainException) {
                $this->assertSame('closed', DB::table('cash_sessions')->where('id', $cash->id)->value('status'));
            } finally {
                DB::table('cash_sessions')->where('id', $cash->id)->update(['status' => 'open']);
            }
            $attachedMembership = Membership::factory()->for($this->company)->for($actor)->create(['role' => 'cashier']);
            try {
                OnlineOrderActionContext::fromLinkedOperation($request, $cash, $actor, $operation, $order);
                $this->fail('Una identidad interna convertida en usuario humano no debe conservar la autorización online.');
            } catch (\DomainException) {
                $this->assertTrue(true);
            } finally {
                $attachedMembership->delete();
            }
            $originalPassword = DB::table('users')->where('id', $actor->id)->value('password');
            DB::table('users')->where('id', $actor->id)->update(['password' => 'altered-credential']);
            try {
                OnlineOrderActionContext::fromLinkedOperation($request, $cash, $actor, $operation, $order);
                $this->fail('Una identidad interna con credenciales alteradas no debe autorizar operaciones.');
            } catch (\DomainException) {
                $this->assertTrue(true);
            } finally {
                DB::table('users')->where('id', $actor->id)->update(['password' => $originalPassword]);
            }
            DB::table('online_order_requests')->where('id', $request->id)->update(['status' => 'preparation_invalidated']);
            try {
                OnlineOrderActionContext::fromLinkedOperation($request, $cash, $actor, $operation, $order);
                $this->fail('Una solicitud invalidada no debe aceptarse mediante un modelo desactualizado.');
            } catch (\DomainException) {
                $this->assertSame('preparation_invalidated', DB::table('online_order_requests')->where('id', $request->id)->value('status'));
            } finally {
                DB::table('online_order_requests')->where('id', $request->id)->update(['status' => 'payment_preparation']);
            }
            $foreignOrder = clone $order;
            $foreignOrder->id += 1000;
            $foreignCompany = clone $order;
            $foreignCompany->company_id += 1000;
            $foreignBranch = clone $order;
            $foreignBranch->branch_id += 1000;
            $foreignCash = clone $cash;
            $foreignCash->id += 1000;
            $unrelatedIntent = new DynamicQrIntent;
            $unrelatedIntent->id = 123456;
            foreach ([
                fn () => $context->assertOrder($foreignOrder, $actor),
                fn () => $context->assertOrder($foreignCompany, $actor),
                fn () => $context->assertOrder($foreignBranch, $actor),
                fn () => $context->assertOrder($order, $this->owner),
                fn () => $context->assertConfirmedPayment($order, $cash, $actor, $unrelatedIntent),
                fn () => OnlineOrderActionContext::fromLinkedOperation($request, $foreignCash, $actor, $operation, $order),
                fn () => OnlineOrderActionContext::fromLinkedOperation($request, $cash, $this->owner, $operation, $order),
                fn () => OnlineOrderActionContext::fromLinkedOperation($request, $cash, $actor, $operation, $foreignOrder),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Un contexto online no debe autorizar otra orden, identidad, ámbito o caja.');
                } catch (\DomainException) {
                    $this->assertTrue(true);
                }
            }
        });
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_http_preparation_creates_one_unpaid_order_with_existing_inventory_reservation(): void
    {
        Http::preventStrayRequests();
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $cash = $this->openCash();
        $unit = Unit::factory()->for($this->company)->create();
        $inventory = InventoryItem::query()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id,
            'product_variant_id' => $this->variant->id, 'name' => 'Stock checkout', 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($this->company, $this->branch, $inventory,
            InventoryMovementType::AdjustmentIn, '2.000', '1.000000', $this->owner, reason: 'Prueba HTTP aislada');
        $input = $this->input();
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk()->assertJsonPath('ready', true);
        $this->postJson(route('storefront.preparation.order'), ['idempotency_key' => $input['idempotency_key']])->assertOk()->assertJsonPath('ready', true);
        $this->postJson(route('storefront.preparation.order'), ['idempotency_key' => $input['idempotency_key']])->assertOk()->assertJsonPath('ready', true);
        $request = OnlineOrderRequest::query()->sole();
        $operation = DB::table('online_qr_operations')->sole();
        $order = $request->posOrder()->firstOrFail();
        $this->assertSame($order->id, $operation->pos_order_id);
        $this->assertSame($cash->id, $operation->cash_session_id);
        $this->assertSame($this->owner->id, $operation->original_cashier_id);
        $this->assertNotSame($this->owner->id, $order->created_by);
        $this->assertSame($operation->system_user_id, $order->created_by);
        $this->assertSame('open', $order->status->value);
        $this->assertSame('24.00', $order->total);
        $item = $order->items()->sole();
        $this->assertSame($this->variant->id, $item->product_variant_id);
        $this->assertSame('2.000', $item->quantity);
        $this->assertSame('12.00', $item->unit_price);
        $this->assertSame('24.00', $item->line_total);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('online_qr_operations', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
        $this->assertDatabaseCount('print_attempts', 0);
        $this->assertSame(0, DB::table('inventory_movements')->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $this->postJson(route('storefront.preparation.cancel'), ['idempotency_key' => $input['idempotency_key']])->assertStatus(409);
    }

    public function test_order_endpoint_is_disabled_by_default_and_checks_the_original_cash(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $cash = $this->openCash();
        $input = $this->input();
        $this->postJson(route('storefront.preparation.store'), $input)->assertOk();
        $this->postJson(route('storefront.preparation.order'), ['idempotency_key' => $input['idempotency_key']])->assertNotFound();
        config()->set('storefront.online_dynamic_qr_enabled', true);
        $cash->update(['active_cash_register_id' => null, 'status' => 'closed', 'closed_at' => now()]);
        $this->postJson(route('storefront.preparation.order'), ['idempotency_key' => $input['idempotency_key']])->assertUnprocessable()->assertJsonValidationErrors('cash_session');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_own_reservation_does_not_hide_changed_historical_quantities(): void
    {
        $preparation = $this->preparationWithOwnReservation();
        DB::table('order_items')->where('order_id', $preparation->pos_order_id)->update(['quantity' => '1.000']);
        try {
            app(OnlinePaymentPreparationService::class)->assertUsable($this->company, $this->branch, $preparation);
            $this->fail('Debe rechazarse una orden que difiere de la solicitud.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_another_actors_order_cannot_be_presented_as_an_online_reservation(): void
    {
        $preparation = $this->preparationWithOwnReservation();
        DB::table('orders')->where('id', $preparation->pos_order_id)->update(['created_by' => $this->owner->id]);
        try {
            app(OnlinePaymentPreparationService::class)->assertUsable($this->company, $this->branch, $preparation);
            $this->fail('La orden de un cajero no puede hacerse pasar por reserva del sistema.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_missing_or_short_reservation_cannot_validate_an_online_order(): void
    {
        $preparation = $this->preparationWithOwnReservation();
        DB::table('inventory_reservations')->where('order_id', $preparation->pos_order_id)->update(['quantity' => '1.000']);
        try {
            app(OnlinePaymentPreparationService::class)->assertUsable($this->company, $this->branch, $preparation);
            $this->fail('Una reserva incompleta no cubre las cantidades del pedido.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_other_company_cannot_use_cash_or_recover_preparation(): void
    {
        config()->set('storefront.delivery_payment_preparation_enabled', true);
        $this->openCash();
        $data = $this->input();
        $this->postJson(route('storefront.preparation.store'), $data)->assertOk();
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        config()->set('storefront.default', ['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id]);
        config()->set('storefront.delivery.branches', [$otherCompany->id.':'.$otherBranch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
        $this->postJson(route('storefront.preparation.resolve'), ['idempotency_key' => $data['idempotency_key']])->assertOk()->assertJsonPath('created', false);
        $data['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.preparation.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('cash_session');
    }
}
