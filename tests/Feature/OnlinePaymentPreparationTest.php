<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
