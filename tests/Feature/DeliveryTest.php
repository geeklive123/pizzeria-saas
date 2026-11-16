<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\DeliveryCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->for($this->company)->create();
        $category = Category::factory()->for($this->company)->create();
        $product = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
        $this->variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'is_active' => true, 'requires_preparation' => false]);
        config()->set('storefront.default', ['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
        config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []]);
        config()->set('storefront.demo_mode', false);
        config()->set('storefront.delivery_payment_preparation_enabled', false);
        config()->set('storefront.online_pos_conversion_enabled', true);
        config()->set('storefront.delivery.branches', [$this->company->id.':'.$this->branch->id => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000',
            'fulfillment' => 'delivery', 'address' => 'Calle de prueba', 'reference' => 'Portón', 'latitude' => -17.3769946,
            'longitude' => -66.1771762, 'location_confirmed' => true, 'items' => [['variant_ulid' => $this->variant->ulid, 'quantity' => 2]]], $extra);
    }

    public function test_boundary_uses_full_precision_and_branch_configuration(): void
    {
        $service = app(DeliveryCoverageService::class);
        $delta = rad2deg(800 / 6371000);
        $this->assertSame('free', $service->calculate($this->branch, -17.3769946 + $delta - 0.0000001, -66.1771762)['coverage']);
        $this->assertSame('free', $service->calculate($this->branch, -17.3769946 + $delta, -66.1771762)['coverage']);
        $this->assertSame('outside', $service->calculate($this->branch, -17.3769946 + $delta + 0.0000001, -66.1771762)['coverage']);
    }

    public function test_preview_and_persisted_coverage_use_the_same_coordinate_precision(): void
    {
        $latitude = -17.3769946 + rad2deg(800 / 6371000);
        $preview = $this->postJson(route('storefront.delivery.coverage'), ['latitude' => $latitude, 'longitude' => -66.1771762])->assertOk()->json('coverage');
        $response = $this->postJson(route('storefront.checkout.store'), $this->payload(['latitude' => $latitude]));
        if ($preview === 'free') {
            $response->assertOk()->assertJsonPath('delivery_coverage', 'free');
            $this->assertSame('free', OnlineOrderRequest::query()->sole()->delivery_coverage);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('location_confirmed');
            $this->assertDatabaseCount('online_order_requests', 0);
        }
    }

    public function test_origin_snapshot_matches_the_precision_used_for_coverage(): void
    {
        config()->set('storefront.delivery.branches.'.$this->company->id.':'.$this->branch->id.'.latitude', -17.37699464);
        $this->postJson(route('storefront.checkout.store'), $this->payload())->assertOk();
        $request = OnlineOrderRequest::query()->sole();
        $this->assertEquals(-17.3769946, $request->delivery_origin_latitude);
        $this->assertEquals(0, $request->delivery_distance_m);
    }

    public function test_free_delivery_is_idempotent_and_confirmed_in_pos_without_a_payment(): void
    {
        $payload = $this->payload(['distance_m' => 999999, 'coverage' => 'quote_required']);
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk()->assertJsonPath('delivery_coverage', 'free');
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk();
        $request = OnlineOrderRequest::query()->sole();
        $this->assertSame('delivery', $request->fulfillment);
        $this->assertSame('not_required', $request->delivery_quote_status);
        $this->assertSame('Calle de prueba', $request->delivery_address);
        $this->assertEquals(0, $request->delivery_distance_m);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
        $owner = User::factory()->create();
        Membership::factory()->for($this->company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $this->assertNotNull($request->fresh()->pos_order_id);
        $this->assertStringContainsString('Calle de prueba', $request->fresh()->posOrder->notes);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->postJson(route('storefront.checkout.store'), array_replace($payload, ['latitude' => -17.38]))->assertUnprocessable();
    }

    public function test_outside_radius_cannot_create_a_request_or_charge(): void
    {
        $this->postJson(route('storefront.checkout.store'), $this->payload(['latitude' => -17.40, 'delivery_quote_acknowledged' => true, 'distance_m' => 1, 'coverage' => 'free']))
            ->assertUnprocessable()->assertJsonValidationErrors('location_confirmed');
        $this->assertDatabaseCount('online_order_requests', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('kitchen_dispatches', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
    }

    public function test_delivery_requires_confirmed_valid_coordinates_and_is_scoped_to_configured_branch(): void
    {
        $this->postJson(route('storefront.checkout.store'), $this->payload(['location_confirmed' => false]))->assertUnprocessable()->assertJsonValidationErrors('location_confirmed');
        $this->postJson(route('storefront.checkout.store'), $this->payload(['latitude' => 91]))->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson(route('storefront.checkout.store'), $this->payload(['address' => '']))->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->postJson(route('storefront.checkout.store'), $this->payload(['reference' => '']))->assertUnprocessable()->assertJsonValidationErrors('reference');
        $other = Branch::factory()->for($this->company)->create();
        config()->set('storefront.default.branch_id', $other->id);
        $this->postJson(route('storefront.checkout.store'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('online_order_requests', 0);
    }

    public function test_historical_pending_quote_still_cannot_convert_to_pos(): void
    {
        $this->postJson(route('storefront.checkout.store'), $this->payload())->assertOk();
        $request = OnlineOrderRequest::query()->sole();
        $request->update(['delivery_coverage' => 'quote_required', 'delivery_quote_status' => 'pending']);
        $owner = User::factory()->create();
        Membership::factory()->for($this->company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertSessionHas('online_order_notice');
        $this->assertNull($request->fresh()->pos_order_id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_delivery_rejects_printer_control_characters_in_customer_text(): void
    {
        foreach (['customer_name', 'address', 'reference', 'notes'] as $field) {
            $this->postJson(route('storefront.checkout.store'), $this->payload([$field => "texto\x1b@"]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('online_order_requests', 0);
    }

    public function test_other_company_cannot_convert_or_view_the_delivery_request(): void
    {
        $this->postJson(route('storefront.checkout.store'), $this->payload())->assertOk();
        $request = OnlineOrderRequest::query()->sole();
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $otherCompany->id, 'active_branch_id' => $otherBranch->id]);
        $this->get(route('online-orders.show', $request->id))->assertNotFound();
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertNotFound();
        $this->assertNull($request->fresh()->pos_order_id);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delivery_request_preserves_a_historical_order(): void
    {
        $old = Order::factory()->for($this->branch)->create(['company_id' => $this->company->id, 'status' => OrderStatus::Paid, 'total' => '45.00']);
        $this->postJson(route('storefront.checkout.store'), $this->payload())->assertOk();
        $this->assertSame(OrderStatus::Paid, $old->fresh()->status);
        $this->assertSame('45.00', $old->fresh()->total);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_photon_results_are_limited_and_failure_returns_safe_error(): void
    {
        Http::fake(fn ($request) => $request['q'] === 'otra calle'
            ? Http::response([], 429)
            : Http::response(['features' => [['geometry' => ['coordinates' => [-66.17, -17.37]], 'properties' => ['name' => '<calle>', 'city' => 'Cochabamba']]]]));
        $this->postJson(route('storefront.delivery.search'), ['query' => 'calle'])->assertOk()->assertJsonPath('results.0.latitude', -17.37)->assertJsonPath('results.0.label', '<calle>, Cochabamba');
        $this->postJson(route('storefront.delivery.search'), ['query' => 'calle'])->assertOk();
        Http::assertSent(fn ($request) => $request['limit'] === 5 && (float) $request['lat'] === -17.3769946 && ! isset($request['lang']));
        Http::assertSentCount(1);
        $this->postJson(route('storefront.delivery.search'), ['query' => 'ab'])->assertUnprocessable();
        $this->postJson(route('storefront.delivery.search'), ['query' => 'otra calle'])->assertStatus(503)->assertDontSee('photon.komoot.io');
    }

    public function test_unavailable_provider_fails_without_leaking_request_details(): void
    {
        Http::fake(fn () => throw new ConnectionException('private provider detail'));
        $this->postJson(route('storefront.delivery.search'), ['query' => 'calle sin servicio'])
            ->assertStatus(503)->assertDontSee('private provider detail');
    }

    public function test_address_search_enforces_a_rate_limit(): void
    {
        Http::fake(['*' => Http::response(['features' => []])]);
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('storefront.delivery.search'), ['query' => 'calle'])->assertOk();
        }
        $this->postJson(route('storefront.delivery.search'), ['query' => 'calle'])->assertStatus(429);
        $this->postJson(route('storefront.checkout.store'), $this->payload())->assertOk();
    }
}
