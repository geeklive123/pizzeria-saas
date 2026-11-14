<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\AddPromotionToOrderAction;
use App\Actions\AddStandaloneExtraAction;
use App\Actions\CancelKitchenDispatchAction;
use App\Actions\CancelKitchenDispatchItemAction;
use App\Actions\CancelOrderAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateOnlineOrderRequestAction;
use App\Actions\DispatchOrderToKitchenWithPrintingAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\RestoreCancelledKitchenDispatchAction;
use App\Actions\UpdateConfiguredPizzaAction;
use App\Actions\UpdateKitchenDispatchDiscountAction;
use App\Actions\UpdateOrderDeliveryChargeAction;
use App\Actions\UpdateOrderItemQuantityAction;
use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Http\Middleware\ResolveStorefrontContext;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\ModifierOption;
use App\Models\OnlineOrderRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionComponent;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\ReportDateRangeService;
use App\Services\SalesReportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Concerns\IsolatedSqliteCommits;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use IsolatedSqliteCommits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('storefront.demo_mode', true);
        config()->set('storefront.online_pos_conversion_enabled', true);
    }

    public function test_public_storefront_uses_the_configured_company_catalog_only(): void
    {
        [$company, $branch, $category, $product] = $this->storefront('Masa & Maña', '+591 70000001');
        $variant = ProductVariant::factory()->for($product)->create([
            'name' => 'Mediana',
            'size_key' => 'mediana',
            'price' => '71.00',
        ]);
        $product->update(['description' => 'Producto temporal con stock directo. Receta pendiente.']);
        $inactiveProduct = Product::factory()->for($category)->create([
            'name' => 'Pizza oculta',
            'type' => ProductType::Pizza,
            'is_active' => false,
        ]);
        ProductVariant::factory()->for($inactiveProduct)->create(['price' => '1.00']);

        [$otherCompany, , $otherCategory] = $this->storefront('Otra pizzería', '+591 70000002');
        $foreignProduct = Product::factory()->for($otherCategory)->create([
            'name' => 'Producto ajeno',
            'type' => ProductType::Pizza,
        ]);
        ProductVariant::factory()->for($foreignProduct)->create();

        $this->configureStorefront($company, $branch);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Masa &amp; Maña', false)
            ->assertSee($product->name)
            ->assertDontSee($inactiveProduct->name)
            ->assertDontSee($foreignProduct->name);

        $this->get(route('storefront.menu'))
            ->assertOk()
            ->assertSee($category->name)
            ->assertSee($product->name)
            ->assertSee($variant->ulid)
            ->assertSee('Consulta sus detalles y disponibilidad')
            ->assertDontSee('Receta pendiente')
            ->assertDontSee($inactiveProduct->name)
            ->assertDontSee($foreignProduct->name);

        $this->assertSame($otherCompany->id, $foreignProduct->company_id);
    }

    public function test_catalog_cards_render_product_images_and_fallbacks(): void
    {
        [$company, $branch, $category, $product] = $this->storefront('Masa & Ma?a', '+591 70000001');
        $product->update(['image_path' => 'products/real.jpg']);
        $first = ProductVariant::factory()->for($product)->create(['name' => 'Mediana']);
        $second = ProductVariant::factory()->for($product)->create(['name' => 'Familiar']);
        $missing = Product::factory()->for($category)->create(['name' => 'Sin foto', 'type' => ProductType::Pizza, 'image_path' => null]);
        ProductVariant::factory()->for($missing)->create();
        $broken = Product::factory()->for($category)->create(['name' => 'Foto ausente', 'type' => ProductType::Pizza, 'image_path' => 'products/missing.jpg']);
        ProductVariant::factory()->for($broken)->create();
        $promotion = $this->storefrontPromotion($company, $category, 'Combo con foto', '55.00');
        $promotion->productVariant->product->update(['image_path' => 'products/combo.jpg']);
        $this->configureStorefront($company, $branch);

        foreach (['storefront.home', 'storefront.menu'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $response->assertSee($product->image_url)->assertSee($missing->image_url)->assertSee($broken->image_url)
                ->assertSee('data-fallback-src="'.asset('images/product-placeholder.svg').'"', false)
                ->assertSee('this.onerror=null;this.src=this.dataset.fallbackSrc', false)
                ->assertSee($first->ulid)->assertSee($second->ulid);
        }

        $this->get(route('storefront.promotions'))->assertOk()
            ->assertSee($promotion->productVariant->product->image_url)
            ->assertSee('data-fallback-src="'.asset('images/product-placeholder.svg').'"', false);
    }

    public function test_location_page_uses_only_configured_business_information(): void
    {
        [$company, $branch] = $this->storefront('Masa & Maña', '+591 77700001');
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        config()->set('storefront.location', [
            'hours' => [[
                'days' => 'Martes a viernes',
                'time' => '16:00 a 22:30',
                'note' => 'Horario confirmado',
            ]],
            'map_url' => 'https://maps.example.test/sucursal',
            'delivery_note' => 'Cobertura confirmada al recibir tu dirección.',
        ]);

        $this->get(route('storefront.location'))
            ->assertOk()
            ->assertSee('Horario de atención')
            ->assertSee('Martes a viernes')
            ->assertSee('16:00 a 22:30')
            ->assertSee('Dirección configurada')
            ->assertSee('+591 77700001')
            ->assertSee('https://wa.me/59177700001', false)
            ->assertSee('https://maps.example.test/sucursal', false)
            ->assertSee('Cobertura confirmada al recibir tu dirección.')
            ->assertDontSee('800 metros')
            ->assertDontSee('57718695');
    }

    public function test_location_page_marks_unconfigured_commercial_details_as_pending(): void
    {
        [$company, $branch] = $this->storefront('Masa & Maña', null);
        $this->configureStorefront($company, $branch);

        $this->get(route('storefront.location'))
            ->assertOk()
            ->assertSee('Horario oficial pendiente de publicación')
            ->assertSee('Por configurar')
            ->assertDontSee('Pedir por WhatsApp')
            ->assertDontSee('Abrir mapa');
    }

    public function test_demo_location_does_not_expose_whatsapp_links(): void
    {
        [$company, $branch] = $this->storefront('Masa & Maña', '+591 77700001');
        $this->configureStorefront($company, $branch);

        $this->get(route('storefront.location'))
            ->assertOk()
            ->assertSee('Contacto desactivado durante la revisión')
            ->assertDontSee('https://wa.me/', false)
            ->assertDontSee('Pedir por WhatsApp');
    }

    public function test_checkout_rejects_a_variant_from_another_company(): void
    {
        [$company, $branch] = $this->storefront('Masa & Maña', '+591 77700001');
        [, , , $foreignProduct] = $this->storefront('Otra empresa', '+591 77700002');
        $foreignVariant = ProductVariant::factory()->for($foreignProduct)->create(['price' => '25.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);

        $this->postJson(route('storefront.checkout.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'customer_name' => 'Cliente',
            'customer_phone' => '70000000',
            'fulfillment' => 'pickup',
            'items' => [['variant_ulid' => $foreignVariant->ulid, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_storefront_shows_only_current_promotions_from_its_company(): void
    {
        [$company, $branch, $category] = $this->storefront('Masa & Maña', '+591 77700001');
        $current = $this->storefrontPromotion($company, $category, 'Combo artesanal vigente', '55.00');
        $inactive = $this->storefrontPromotion($company, $category, 'Combo inactivo', '40.00', false);
        $future = $this->storefrontPromotion(
            $company,
            $category,
            'Combo futuro',
            '42.00',
            startsAt: now()->addDay(),
        );
        $expired = $this->storefrontPromotion(
            $company,
            $category,
            'Combo vencido',
            '38.00',
            endsAt: now()->subDay(),
        );
        [$otherCompany, , $otherCategory] = $this->storefront('Otra empresa', '+591 70000002');
        $foreign = $this->storefrontPromotion($otherCompany, $otherCategory, 'Promoción ajena', '10.00');
        $this->configureStorefront($company, $branch);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee($current->productVariant->product->name)
            ->assertSee('55.00')
            ->assertDontSee($inactive->productVariant->product->name)
            ->assertDontSee($future->productVariant->product->name)
            ->assertDontSee($expired->productVariant->product->name)
            ->assertDontSee($foreign->productVariant->product->name);

        $this->get(route('storefront.promotions'))
            ->assertOk()
            ->assertSee('Promociones')
            ->assertSee($current->productVariant->product->name)
            ->assertSee($current->productVariant->ulid)
            ->assertDontSee($inactive->productVariant->product->name)
            ->assertDontSee($future->productVariant->product->name)
            ->assertDontSee($expired->productVariant->product->name)
            ->assertDontSee($foreign->productVariant->product->name);

        $this->get(route('storefront.menu'))
            ->assertOk()
            ->assertDontSee($current->productVariant->product->name);
    }

    public function test_checkout_rejects_a_promotion_that_is_no_longer_current(): void
    {
        [$company, $branch, $category] = $this->storefront('Masa & Maña', '+591 77700001');
        $expired = $this->storefrontPromotion(
            $company,
            $category,
            'Promoción vencida',
            '35.00',
            endsAt: now()->subMinute(),
        );
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        config()->set('storefront.demo_mode', false);

        $this->postJson(route('storefront.checkout.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'customer_name' => 'Cliente',
            'customer_phone' => '70000000',
            'fulfillment' => 'pickup',
            'items' => [[
                'variant_ulid' => $expired->productVariant->ulid,
                'quantity' => 1,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_legacy_whatsapp_checkout_is_gone(): void
    {
        [$company, $branch] = $this->storefront('Masa y Mana', null);
        $this->configureStorefront($company, $branch);
        $this->postJson(route('storefront.checkout.whatsapp'), [])->assertStatus(410);
        $this->assertDatabaseCount('online_order_requests', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_database_failure_does_not_expose_customer_data_or_sql(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create();
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->mock(CreateOnlineOrderRequestAction::class)
            ->shouldReceive('execute')->once()->andThrow(new QueryException('test', 'INSERT secret', ['private'], new \RuntimeException('private')));
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente Privado', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]])
            ->assertStatus(503)->assertDontSee('Cliente Privado')->assertDontSee('INSERT secret')->assertDontSee('private');
    }

    public function test_online_request_is_persisted_once_and_recovered_with_historical_prices(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['name' => 'Familiar', 'price' => '89.50']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'customer_name' => ' Cliente ', 'customer_phone' => '70000000',
            'fulfillment' => 'pickup', 'notes' => 'Sin picante', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2, 'price' => '0.01']]];
        $first = $this->postJson(route('storefront.checkout.store'), $payload)->assertOk()->assertJsonPath('subtotal', '179.00');
        $this->assertStringStartsWith('WEB-', $first->json('code'));
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk()->assertJsonPath('code', $first->json('code'));
        $this->postJson(route('storefront.checkout.recover'), ['idempotency_key' => $key])->assertOk()->assertJsonPath('subtotal', '179.00')->assertJsonMissingPath('customer_name');
        $this->postJson(route('storefront.checkout.store'), array_replace($payload, ['notes' => 'Otro']))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $variant->update(['price' => '100.00']);
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk()->assertJsonPath('subtotal', '179.00');
        $this->assertDatabaseCount('online_order_requests', 1);
        $this->assertDatabaseHas('online_order_request_items', ['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => '89.5']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_resolving_an_uncertain_key_fences_late_submission_or_recovers_the_order(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
        $this->postJson(route('storefront.checkout.resolve'), ['idempotency_key' => $payload['idempotency_key']])->assertOk()->assertJsonPath('created', false);
        $fence = OnlineOrderRequest::query()->where('idempotency_key', $payload['idempotency_key'])->firstOrFail();
        $this->postJson(route('storefront.checkout.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson(route('storefront.checkout.resolve'), ['idempotency_key' => $payload['idempotency_key']])->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('online_order_request_items', 0);
        $payload['idempotency_key'] = (string) Str::uuid();
        $order = $this->postJson(route('storefront.checkout.store'), $payload)->assertOk();
        $this->postJson(route('storefront.checkout.resolve'), ['idempotency_key' => $payload['idempotency_key']])->assertOk()->assertJsonPath('created', true)->assertJsonPath('code', $order->json('code'));
        $this->assertDatabaseCount('online_order_request_items', 1);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('online-orders.index', ['status' => 'cancelled']))->assertOk()->assertDontSee($fence->publicCode());
        $this->get(route('online-orders.show', $fence->id))->assertNotFound();
    }

    public function test_online_request_only_accepts_valid_pickup_and_available_products(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create();
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $data = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
        foreach (['customer_name', 'customer_phone'] as $field) {
            $this->postJson(route('storefront.checkout.store'), array_replace($data, [$field => '']))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson(route('storefront.checkout.store'), array_replace($data, ['fulfillment' => 'delivery']))->assertUnprocessable()->assertJsonValidationErrors('fulfillment');
        $this->postJson(route('storefront.checkout.store'), array_replace($data, ['items' => []]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson(route('storefront.checkout.store'), array_replace($data, ['items' => [['variant_ulid' => $variant->ulid, 'quantity' => 21]]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->postJson(route('storefront.checkout.store'), array_replace($data, ['notes' => str_repeat('a', 1001)]))->assertUnprocessable()->assertJsonValidationErrors('notes');
        $product->update(['is_active' => false]);
        $this->postJson(route('storefront.checkout.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('online_order_requests', 0);
    }

    public function test_online_request_administration_is_scoped_and_transitions_only_once(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $data = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
        $this->postJson(route('storefront.checkout.store'), $data)->assertOk();
        $order = OnlineOrderRequest::query()->firstOrFail();
        $originalName = $product->name;
        $originalVariant = $variant->name;
        $product->update(['name' => 'Nombre nuevo']);
        $variant->update(['name' => 'Nueva variante', 'price' => '99.00']);
        $this->get(route('online-orders.index'))->assertRedirect();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('online-orders.index', ['status' => 'pending_confirmation']))->assertOk()->assertSee($order->publicCode());
        $this->get(route('online-orders.show', $order->id))->assertOk()->assertSee('https://wa.me/59170000000?text=', false)
            ->assertSee($originalName)->assertSee($originalVariant)->assertSee('12.00')->assertDontSee('Nombre nuevo');
        $otherBranch = Branch::factory()->for($company)->create();
        $foreign = OnlineOrderRequest::query()->create(['company_id' => $company->id, 'branch_id' => $otherBranch->id, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_name' => 'Ajeno', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'status' => 'pending_confirmation', 'subtotal' => '1.00', 'total' => '1.00']);
        $this->get(route('online-orders.show', $foreign->id))->assertNotFound();
        [$otherCompany, $otherCompanyBranch] = $this->storefront('Otro restaurante', null);
        $foreignCompany = OnlineOrderRequest::query()->create(['company_id' => $otherCompany->id, 'branch_id' => $otherCompanyBranch->id, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'customer_name' => 'Ajeno', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'status' => 'pending_confirmation', 'subtotal' => '1.00', 'total' => '1.00']);
        $this->get(route('online-orders.show', $foreignCompany->id))->assertNotFound();
        $this->post(route('online-orders.transition', $order->id), ['status' => 'confirmed'])->assertRedirect();
        $this->assertDatabaseHas('online_order_requests', ['id' => $order->id, 'status' => 'confirmed', 'resolved_by' => $owner->id]);
        $this->post(route('online-orders.transition', $order->id), ['status' => 'cancelled'])->assertRedirect(route('online-orders.show', $order->id))->assertSessionHas('online_order_notice');
        $this->get(route('online-orders.index', ['status' => 'pending_confirmation']))->assertOk()->assertDontSee($order->publicCode());
        $this->get(route('online-orders.index', ['from' => now()->addDay()->format('Y-m-d')]))->assertOk()->assertDontSee($order->publicCode());
        $order->update(['created_at' => '2026-11-14 02:30:00']);
        $this->get(route('online-orders.index', ['from' => '2026-11-13', 'to' => '2026-11-13']))->assertOk()->assertSee($order->publicCode());
        $this->get(route('online-orders.index', ['from' => '2026-11-14', 'to' => '2026-11-14']))->assertOk()->assertDontSee($order->publicCode());
        $this->get(route('online-orders.index', ['to' => '2026-11-13']))->assertOk()->assertSee($order->publicCode());
    }

    public function test_conversion_preserves_historical_pizza_price_and_retries_without_payment(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['name' => 'Familiar', 'price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $variant->update(['price' => '99.00']);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $request->refresh();
        $this->assertSame('confirmed', $request->status);
        $this->assertNotNull($request->pos_order_id);
        $this->assertSame($owner->id, $request->converted_by);
        $order = $request->posOrder()->firstOrFail();
        $this->assertSame('24.00', $order->total);
        $this->assertSame('24.00', $order->pizza_base_subtotal);
        $this->assertSame('0.00', $order->extras_subtotal);
        $this->assertSame('24.00', $order->financial_snapshot['gross_subtotal']);
        $this->assertSame('24.00', $order->financial_snapshot['pizza_base_subtotal']);
        $this->assertSame('24.00', $order->financial_snapshot['total']);
        $this->assertSame('12.00', $order->items()->firstOrFail()->unit_price);
        $this->assertSame('12.00', $order->items()->firstOrFail()->sections()->firstOrFail()->unit_price_snapshot);
        $item = $order->items()->firstOrFail();
        try {
            app(UpdateOrderItemQuantityAction::class)->execute($item, 3, $owner);
            $this->fail('No debe editarse una pizza de una solicitud online.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('solicitud online', $exception->getMessage());
        }
        try {
            app(UpdateConfiguredPizzaAction::class)->execute($item, [['product_variant' => $variant]], 2, $owner, OrderType::Takeaway);
            $this->fail('No debe reemplazarse la composición de una pizza online.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('solicitud online', $exception->getMessage());
        }
        $this->assertSame('12.00', $item->fresh()->unit_price);
        $this->assertSame('24.00', $order->fresh()->total);
        $promotion = $this->storefrontPromotion($company, $product->category, 'Combo de prueba', '55.00');
        $extra = new ModifierOption;
        $extra->forceFill(['company_id' => $company->id]);
        foreach ([
            fn () => app(AddOrderItemAction::class)->execute($order, $variant, 1, $owner),
            fn () => app(AddPromotionToOrderAction::class)->execute($order, $promotion, 1, $owner),
            fn () => app(AddStandaloneExtraAction::class)->execute($order, $extra, 1, $owner),
            fn () => app(CancelOrderItemAction::class)->execute($item, $owner),
            fn () => app(UpdateOrderDeliveryChargeAction::class)->execute($order, $owner, '10.00'),
            fn () => app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order, $owner, '10'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('No debe cambiarse el importe de una solicitud online.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('solicitud online', $exception->getMessage());
            }
        }
        $this->assertSame('24.00', $order->fresh()->total);
        $this->assertSame('12.00', $item->fresh()->sections()->firstOrFail()->unit_price_snapshot);
        $this->put(route('orders.items.update', [$order->ulid, $item->ulid]), [
            'quantity' => '2', 'fulfillment_type' => 'takeaway', 'unit_price' => '0.01', 'line_total' => '0.02',
        ])->assertSessionHasErrors('item');
        $this->assertSame('24.00', $order->fresh()->total);
        $this->assertSame('12.00', $item->fresh()->unit_price);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('kitchen_dispatches', 0);
        $this->get(route('online-orders.show', $request->id))->assertOk()->assertSee('Abrir orden POS');
        $dispatch = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order->fresh(), $owner)->dispatch;
        $this->assertNotNull($dispatch);
        $this->assertSame('24.00', $dispatch->total);
        $this->assertSame('24.00', $order->fresh()->total);
        try {
            app(UpdateKitchenDispatchDiscountAction::class)->execute($dispatch, $owner, '10');
            $this->fail('No debe cambiarse el descuento de una solicitud online.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('solicitud online', $exception->getMessage());
        }
        $this->assertNull(app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order->fresh(), $owner)->dispatch);
        $this->assertDatabaseCount('kitchen_dispatches', 1);
        $this->assertDatabaseCount('payments', 0);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        app(RegisterPaymentAction::class)->execute($order->fresh(), $session, PaymentMethod::Cash, '10.00', $owner, 'online-test-cash-partial', '10.00', dispatch: $dispatch);
        $this->assertSame('24.00', $order->fresh()->total);
        app(RegisterPaymentAction::class)->execute($order->fresh(), $session, PaymentMethod::Cash, '14.00', $owner, 'online-test-cash-final', '14.00', dispatch: $dispatch);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame('24.00', $order->fresh()->total);
    }

    public function test_online_sale_reserves_consumes_and_reports_the_actual_partial_payments(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $product->update(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
        $unit = Unit::factory()->for($company)->create();
        $stockItem = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $variant->id, 'name' => 'Bebida prueba', 'is_active' => true]);
        $stock = InventoryStock::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $stockItem->id, 'quantity' => '10.000', 'average_cost' => '1.000000']);
        InventoryBatch::factory()->for($company)->for($branch)->for($stockItem)->create(['quantity_received' => '10.000', 'quantity_remaining' => '10.000']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]];
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk();
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk();
        $this->assertDatabaseCount('online_order_requests', 1);
        $request = OnlineOrderRequest::query()->firstOrFail();
        $this->assertDatabaseCount('payments', 0);
        $variant->update(['price' => '99.00']);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $order = $request->fresh()->posOrder;
        $this->assertSame(OrderType::Takeaway, $order->type);
        $this->assertSame('24.00', $order->total);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'inventory_item_id' => $stockItem->id, 'status' => 'reserved', 'quantity' => 2]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('kitchen_dispatches', 0);
        $this->assertSame('10.000', $stock->fresh()->quantity);
        $dispatch = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order, $owner)->dispatch;
        $this->assertNotNull($dispatch);
        $this->assertNull(app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order->fresh(), $owner)->dispatch);
        $this->assertDatabaseCount('kitchen_dispatches', 1);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'inventory_item_id' => $stockItem->id, 'status' => 'consumed']);
        $this->assertDatabaseHas('inventory_movements', ['inventory_item_id' => $stockItem->id, 'type' => 'order_consumption', 'quantity' => 2]);
        $this->assertSame('8.000', $stock->fresh()->quantity);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        app(RegisterPaymentAction::class)->execute($order->fresh(), $session, PaymentMethod::Cash, '10.00', $owner, 'online-stock-partial', '10.00', dispatch: $dispatch);
        $this->assertSame('14.00', app(OrderPaymentService::class)->balance($order->fresh()));
        app(RegisterPaymentAction::class)->execute($order->fresh(), $session, PaymentMethod::Cash, '14.00', $owner, 'online-stock-final', '14.00', dispatch: $dispatch);
        $this->assertSame('0.00', app(OrderPaymentService::class)->balance($order->fresh()));
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
        $report = app(SalesReportService::class)->data($company, $branch, app(ReportDateRangeService::class)->from(['preset' => 'today']));
        $this->assertSame('24.00', $report['sales_total']);
        $this->assertSame(1, $report['orders_paid']);
        $this->assertSame('24.00', $report['payments']->firstWhere('method', PaymentMethod::Cash->value)['amount']);
    }

    public function test_conversion_failure_rolls_back_and_feature_flag_keeps_request_pending(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        config()->set('storefront.online_pos_conversion_enabled', false);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertSessionHas('online_order_notice');
        config()->set('storefront.online_pos_conversion_enabled', true);
        $line = $request->items()->firstOrFail();
        $line->update(['was_promotion' => null]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])
            ->assertSessionHas('online_order_notice', 'La solicitud no conserva la clasificación histórica del producto. Revísala con el cliente.');
        $line->update(['was_promotion' => false]);
        $variant->update(['is_active' => false]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertSessionHas('online_order_notice');
        $this->assertSame('pending_confirmation', $request->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_legacy_request_requires_contact_and_records_audited_cancellation_without_pos_order(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $request->items()->update(['was_promotion' => null]);
        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingAs($kitchen)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('online-orders.legacy-review', $request->id), ['note' => 'Cliente informado', 'contacted' => '1'])->assertForbidden();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $otherBranch = Branch::factory()->for($company)->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $otherBranch->id])
            ->post(route('online-orders.legacy-review', $request->id), ['note' => 'Cliente informado', 'contacted' => '1'])->assertNotFound();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('online-orders.index'))->assertOk()->assertSee('Requiere revisión histórica');
        $this->get(route('online-orders.show', $request->id))->assertOk()->assertSee('No se puede convertir al POS')
            ->assertSee('Contactar por WhatsApp')->assertSee('Registrar revisión y cancelar solicitud')->assertDontSee('Confirmar pedido');
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertSessionHas('online_order_notice');
        $this->post(route('online-orders.transition', $request->id), ['status' => 'cancelled'])->assertSessionHas('online_order_notice');
        $this->post(route('online-orders.legacy-review', $request->id), ['note' => 'Cliente informado'])->assertSessionHasErrors('contacted');
        $this->assertSame('pending_confirmation', $request->fresh()->status);
        $this->post(route('online-orders.legacy-review', $request->id), ['note' => 'Cliente informado', 'contacted' => '1'])->assertRedirect();
        $resolved = $request->fresh();
        $this->assertSame('cancelled', $resolved->status);
        $this->assertSame('cancelled_after_contact', $resolved->legacy_review_resolution);
        $this->assertSame($owner->id, $resolved->legacy_reviewed_by);
        $this->assertNotNull($resolved->legacy_reviewed_at);
        $this->assertSame('24.00', $resolved->total);
        $this->assertNull($resolved->items()->firstOrFail()->was_promotion);
        $this->post(route('online-orders.legacy-review', $request->id), ['note' => 'Segundo intento', 'contacted' => '1'])->assertSessionHas('online_order_notice', 'Esta solicitud ya fue atendida. Se conserva el estado actual.');
        $this->assertSame('Cliente informado', $request->fresh()->legacy_review_note);
        $this->get(route('online-orders.show', $request->id))->assertOk()->assertSee('Cliente informado');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_converted_order_can_be_cancelled_as_a_whole(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $order = $request->fresh()->posOrder;
        app(CancelOrderAction::class)->execute($order, $owner, 'Cancelación operativa');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('12.00', $request->fresh()->total);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_converted_order_dispatch_can_be_cancelled_and_restored_as_a_whole(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $order = $request->fresh()->posOrder;
        $dispatch = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order, $owner)->dispatch;
        $item = $order->items()->firstOrFail();
        try {
            app(CancelKitchenDispatchItemAction::class)->execute($item, $owner, 'Prueba');
            $this->fail('No debe anularse solo una línea online.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('solicitud online', $exception->getMessage());
        }
        app(CancelKitchenDispatchAction::class)->execute($dispatch, $owner, 'Cancelación operativa');
        app(RestoreCancelledKitchenDispatchAction::class)->execute($dispatch->fresh(), $owner, 'Restauración operativa');
        $this->assertSame('12.00', $order->fresh()->total);
        $this->assertSame('12.00', $item->fresh()->unit_price);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_conversion_preserves_and_protects_non_pizza_price(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $product->update(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 2]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $variant->update(['price' => '99.00']);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertRedirect();
        $order = $request->fresh()->posOrder;
        $this->assertSame('24.00', $order->total);
        $this->assertSame('24.00', $order->other_subtotal);
        $item = $order->items()->firstOrFail();
        try {
            app(UpdateOrderItemQuantityAction::class)->execute($item, 3, $owner);
            $this->fail('No debe editarse una línea de una solicitud online.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('solicitud online', $exception->getMessage());
        }
        $this->assertSame('12.00', $item->fresh()->unit_price);
        $this->assertSame('24.00', $order->fresh()->total);
    }

    public function test_conversion_blocks_promotion_without_historical_composition(): void
    {
        [$company, $branch, $category, $product] = $this->storefront('Masa y Mana', null);
        $stockVariant = ProductVariant::factory()->for($product)->create();
        $unit = Unit::factory()->for($company)->create();
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $stockVariant->id, 'name' => 'Componente', 'is_active' => true]);
        InventoryStock::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => '10.000', 'average_cost' => '1.000000']);
        InventoryBatch::factory()->for($company)->for($branch)->for($item)->create(['quantity_received' => '10.000', 'quantity_remaining' => '10.000']);
        $promotion = $this->storefrontPromotion($company, $category, 'Combo', '55.00');
        PromotionComponent::query()->create(['company_id' => $company->id, 'promotion_id' => $promotion->id, 'inventory_item_id' => $item->id, 'quantity' => '1.000']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $promotion->productVariant->ulid, 'quantity' => 1]]])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])
            ->assertSessionHas('online_order_notice', 'La promoción no contiene una configuración histórica verificable. Contacta al cliente antes de continuar.');
        $promotion->components()->delete();
        $promotion->productVariant->product->update(['type' => ProductType::Beverage]);
        $promotion->delete();
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])
            ->assertSessionHas('online_order_notice', 'La promoción no contiene una configuración histórica verificable. Contacta al cliente antes de continuar.');
        $this->assertSame('pending_confirmation', $request->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_conversion_rolls_back_previous_reservations_when_later_line_runs_out(): void
    {
        [$company, $branch, $category, $product] = $this->storefront('Masa y Mana', null);
        $product->update(['type' => ProductType::Beverage]);
        $secondProduct = Product::factory()->for($category)->create(['type' => ProductType::Beverage, 'is_active' => true]);
        $variants = collect([$product, $secondProduct])->map(fn ($p) => ProductVariant::factory()->for($p)->create(['price' => '12.00', 'requires_preparation' => false]))->sortBy('ulid')->values();
        $unit = Unit::factory()->for($company)->create();
        $stocks = [];
        foreach ($variants as $variant) {
            $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $variant->id, 'name' => 'Stock '.$variant->id, 'is_active' => true]);
            $stocks[] = InventoryStock::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => '10.000', 'average_cost' => '1.000000']);
            InventoryBatch::factory()->for($company)->for($branch)->for($item)->create(['quantity_received' => '10.000', 'quantity_remaining' => '10.000']);
        }
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => $variants->map(fn ($v) => ['variant_ulid' => $v->ulid, 'quantity' => 1])->all()])->assertOk();
        $request = OnlineOrderRequest::query()->firstOrFail();
        $stocks[1]->update(['quantity' => '0.000']);
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $request->id), ['status' => 'confirmed'])->assertSessionHas('online_order_notice');
        $this->assertSame('pending_confirmation', $request->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_online_request_rejects_unknown_products_and_obeys_demo_mode(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create();
        $this->configureStorefront($company, $branch);
        $data = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];
        $this->postJson(route('storefront.checkout.store'), $data)->assertForbidden();
        config()->set('storefront.demo_mode', false);
        $data['items'][0]['variant_ulid'] = (string) Str::ulid();
        $this->postJson(route('storefront.checkout.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson(route('storefront.checkout.recover'), ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('online_order_requests', 0);
    }

    public function test_online_request_accepts_maximum_quantity_and_notes(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '10.50']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'notes' => str_repeat('a', 1000), 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 20]]])->assertOk()->assertJsonPath('subtotal', '210.00');
        $this->assertDatabaseHas('online_order_requests', ['notes' => str_repeat('a', 1000), 'total' => '210']);
    }

    public function test_online_promotion_preserves_price_and_does_not_reserve_stock(): void
    {
        [$company, $branch, $category, $product] = $this->storefront('Masa y Mana', null);
        $stockVariant = ProductVariant::factory()->for($product)->create();
        $unit = Unit::factory()->for($company)->create();
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $stockVariant->id, 'name' => 'Componente', 'is_active' => true]);
        InventoryStock::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => '10.000', 'average_cost' => '1.000000']);
        InventoryBatch::factory()->for($company)->for($branch)->for($item)->create(['quantity_received' => '10.000', 'quantity_remaining' => '10.000']);
        $promotion = $this->storefrontPromotion($company, $category, 'Combo', '55.00');
        PromotionComponent::query()->create(['company_id' => $company->id, 'promotion_id' => $promotion->id, 'inventory_item_id' => $item->id, 'quantity' => '1.000']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $payload = ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $promotion->productVariant->ulid, 'quantity' => 2]]];
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk()->assertJsonPath('subtotal', '110.00');
        $promotion->productVariant->update(['price' => '99.00']);
        $this->postJson(route('storefront.checkout.store'), $payload)->assertOk()->assertJsonPath('subtotal', '110.00');
        $promotion->update(['ends_at' => now()->subMinute()]);
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.checkout.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('online_order_request_items', ['product_name' => 'Combo', 'unit_price' => '55']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_online_request_cancellation_and_admin_permission(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create();
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]])->assertOk();
        $order = OnlineOrderRequest::query()->firstOrFail();
        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingAs($kitchen)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('online-orders.index'))->assertForbidden();
        $this->post(route('online-orders.transition', $order->id), ['status' => 'cancelled'])->assertForbidden();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('online-orders.transition', $order->id), ['status' => 'cancelled'])->assertRedirect();
        $this->assertDatabaseHas('online_order_requests', ['id' => $order->id, 'status' => 'cancelled', 'resolved_by' => $owner->id]);
        $this->post(route('online-orders.transition', $order->id), ['status' => 'confirmed'])->assertRedirect(route('online-orders.show', $order->id))->assertSessionHas('online_order_notice');
        $this->get(route('online-orders.index', ['status' => 'cancelled']))->assertOk()->assertSee($order->publicCode());
    }

    public function test_repeated_or_conflicting_transitions_show_notice_and_preserve_first_audit(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['name' => 'Familiar', 'price' => '12.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        $first = User::factory()->create(['name' => 'Primera persona']);
        $second = User::factory()->create(['name' => 'Segunda persona']);
        Membership::factory()->for($company)->for($first)->owner()->create();
        Membership::factory()->for($company)->for($second)->owner()->create();

        foreach ([['confirmed', 'confirmed'], ['confirmed', 'cancelled'], ['cancelled', 'cancelled'], ['cancelled', 'confirmed']] as [$initial, $repeated]) {
            $this->postJson(route('storefront.checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup', 'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]])->assertOk();
            $order = OnlineOrderRequest::query()->latest('id')->firstOrFail();
            $this->actingAs($first)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
                ->post(route('online-orders.transition', $order->id), ['status' => $initial])->assertRedirect();
            $resolved = $order->fresh();
            $timestamp = $resolved->resolved_at->toISOString();
            $this->travel(1)->minutes();
            $this->actingAs($second)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
                ->post(route('online-orders.transition', $order->id), ['status' => $repeated])
                ->assertRedirect(route('online-orders.show', $order->id))
                ->assertSessionHas('online_order_notice', 'Esta solicitud ya fue atendida. Se conserva el estado actual.');
            $this->get(route('online-orders.show', $order->id))->assertOk()
                ->assertSee('Esta solicitud ya fue atendida. Se conserva el estado actual.')
                ->assertSee($initial === 'confirmed' ? 'Confirmado' : 'Cancelado')
                ->assertSee('Primera persona')->assertSee('Familiar')->assertSee('12.00')
                ->assertDontSee('Confirmar solicitud')->assertDontSee('Cancelar solicitud');
            $this->assertSame($initial, $order->fresh()->status);
            $this->assertSame($first->id, $order->fresh()->resolved_by);
            $this->assertSame($timestamp, $order->fresh()->resolved_at->toISOString());
        }
    }

    public function test_checkout_requires_contact_details_and_rejects_invalid_items(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa y Mana', null);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '10.00']);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);
        config()->set('storefront.demo_mode', false);
        $payload = ['idempotency_key' => (string) Str::uuid(),
            'customer_name' => 'Cliente', 'customer_phone' => '70000000', 'fulfillment' => 'pickup',
            'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]]];

        $this->get(route('storefront.checkout'))->assertOk()->assertDontSee('customer_email')->assertSee('Realizar pedido');
        foreach (['customer_name', 'customer_phone'] as $field) {
            $this->postJson(route('storefront.checkout.store'), array_replace($payload, [$field => '  ']))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson(route('storefront.checkout.store'), array_replace($payload, ['customer_phone' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        foreach ([0, 21] as $quantity) {
            $this->postJson(route('storefront.checkout.store'), array_replace($payload, ['items' => [['variant_ulid' => $variant->ulid, 'quantity' => $quantity]]]))
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        }
        $response = $this->postJson(route('storefront.checkout.store'), $payload)->assertOk();
        $response->assertJsonPath('subtotal', '10.00');
        $product->update(['is_active' => false]);
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson(route('storefront.checkout.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_visibility_list_and_active_category_are_required_for_publication(): void
    {
        [$company, $branch, $category, $visible] = $this->storefront('Masa & Maña', '+591 77700001');
        ProductVariant::factory()->for($visible)->create();
        $hidden = Product::factory()->for($category)->create(['name' => 'Producto interno']);
        ProductVariant::factory()->for($hidden)->create();
        $inactiveCategory = Category::factory()->for($company)->create(['name' => 'Categoría inactiva', 'is_active' => false]);
        $inactiveCategoryProduct = Product::factory()->for($inactiveCategory)->create(['name' => 'Producto de categoría inactiva']);
        ProductVariant::factory()->for($inactiveCategoryProduct)->create();
        $this->configureStorefront($company, $branch);
        config()->set('storefront.visibility.category_ulids', [$category->ulid, $inactiveCategory->ulid]);
        config()->set('storefront.visibility.product_ulids', [$visible->ulid, $inactiveCategoryProduct->ulid]);

        $this->get(route('storefront.menu'))
            ->assertOk()
            ->assertSee($visible->name)
            ->assertDontSee($hidden->name)
            ->assertDontSee($inactiveCategoryProduct->name);

        config()->set('storefront.visibility.category_ulids', []);
        $this->get(route('storefront.menu'))
            ->assertOk()
            ->assertDontSee($visible->name)
            ->assertSee('No hay productos activos en esta tienda.');
    }

    public function test_checkout_rejects_stock_unavailable_in_the_storefront_branch(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa & Maña', '+591 77700001');
        $otherBranch = Branch::factory()->for($company)->create();
        $product->update(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($product)->create([
            'requires_preparation' => false,
            'price' => '12.00',
        ]);
        $unit = Unit::factory()->for($company)->create();
        $inventoryItem = InventoryItem::query()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $unit->getKey(),
            'product_variant_id' => $variant->getKey(),
            'name' => $product->name,
            'is_active' => true,
        ]);
        InventoryStock::query()->create([
            'company_id' => $company->getKey(),
            'branch_id' => $otherBranch->getKey(),
            'inventory_item_id' => $inventoryItem->getKey(),
            'quantity' => '10.000',
            'average_cost' => '4.000000',
        ]);
        InventoryBatch::factory()->for($company)->for($otherBranch)->for($inventoryItem)->create([
            'quantity_received' => '10.000',
            'quantity_remaining' => '10.000',
            'unit_cost' => '4.000000',
        ]);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);

        $this->get(route('storefront.menu'))
            ->assertOk()
            ->assertSee('Sin stock');

        $this->postJson(route('storefront.checkout.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'customer_name' => 'Cliente',
            'customer_phone' => '70000000',
            'fulfillment' => 'pickup',
            'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_host_mapping_can_resolve_a_different_storefront_context(): void
    {
        [$defaultCompany, $defaultBranch] = $this->storefront('Tienda principal', '+591 70000001');
        [$domainCompany, $domainBranch] = $this->storefront('Tienda por dominio', '+591 70000002');

        $this->configureStorefront($defaultCompany, $defaultBranch);
        config()->set('storefront.domains', [
            'pedidos.masa.test' => [
                'company_id' => $domainCompany->id,
                'branch_id' => $domainBranch->id,
            ],
        ]);

        $request = Request::create('http://pedidos.masa.test/tienda');
        app(ResolveStorefrontContext::class)->handle($request, fn () => response('ok'));

        $this->assertTrue($request->attributes->get('storefrontCompany')->is($domainCompany));
        $this->assertTrue($request->attributes->get('storefrontBranch')->is($domainBranch));
        $this->assertFalse($request->attributes->get('storefrontCompany')->is($defaultCompany));
    }

    /** @return array{Company, Branch, Category, Product} */
    private function storefront(string $companyName, ?string $phone): array
    {
        $company = Company::factory()->create(['name' => $companyName, 'phone' => $phone]);
        $branch = Branch::factory()->for($company)->create([
            'name' => $companyName.' Central',
            'address' => 'Dirección configurada',
            'phone' => $phone,
        ]);
        $category = Category::factory()->for($company)->create([
            'name' => 'Pizzas artesanales '.$company->id,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->for($category)->create([
            'name' => 'Pizza artesanal '.$company->id,
            'type' => ProductType::Pizza,
            'description' => 'Ingredientes de la carta real.',
        ]);

        return [$company, $branch, $category, $product];
    }

    private function configureStorefront(Company $company, Branch $branch): void
    {
        config()->set('storefront.default', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);
        config()->set('storefront.visibility', [
            'category_names' => [],
            'category_ulids' => Category::query()->forCompany($company)->pluck('ulid')->all(),
            'product_names' => [],
            'product_ulids' => [],
        ]);
    }

    private function storefrontPromotion(
        Company $company,
        Category $category,
        string $name,
        string $price,
        bool $active = true,
        mixed $startsAt = null,
        mixed $endsAt = null,
    ): Promotion {
        $product = Product::factory()->for($category)->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'type' => ProductType::Combo,
            'description' => 'Descripción pública de '.$name.'.',
            'is_active' => true,
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'company_id' => $company->getKey(),
            'name' => 'Promoción',
            'price' => $price,
            'is_active' => true,
        ]);

        return Promotion::factory()->create([
            'company_id' => $company->getKey(),
            'product_variant_id' => $variant->getKey(),
            'is_active' => $active,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->load('productVariant.product');
    }
}
