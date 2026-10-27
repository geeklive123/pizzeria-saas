<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Http\Middleware\ResolveStorefrontContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    public function test_checkout_builds_whatsapp_message_with_server_prices_without_creating_operations(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa & Maña', '+591 77700001');
        $variant = ProductVariant::factory()->for($product)->create([
            'name' => 'Familiar',
            'size_key' => 'familiar',
            'price' => '89.50',
        ]);
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);

        $response = $this->postJson(route('storefront.checkout.whatsapp'), [
            'customer_name' => 'Valeria Méndez',
            'customer_phone' => '76428910',
            'customer_email' => 'valeria@example.com',
            'fulfillment' => 'delivery',
            'address' => 'Av. Principal 320',
            'reference' => 'Portón rojo',
            'notes' => 'Sin picante',
            'items' => [[
                'variant_ulid' => $variant->ulid,
                'quantity' => 2,
                'price' => '0.01',
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('subtotal', '179.00')
            ->assertJsonPath('item_count', 2);

        $payload = $response->json();
        $this->assertStringStartsWith('https://wa.me/59177700001?text=', $payload['url']);
        $this->assertStringContainsString($product->name, $payload['message']);
        $this->assertStringContainsString('2 × '.$product->name.' (Familiar) — 179 Bs.', $payload['message']);
        $this->assertStringContainsString('Costo de delivery: por confirmar', $payload['message']);
        $this->assertStringNotContainsString('0.01', $payload['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_demo_checkout_validates_the_cart_without_generating_a_whatsapp_link(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa & Maña', null);
        $variant = ProductVariant::factory()->for($product)->create([
            'name' => 'Mediana',
            'price' => '71.00',
        ]);
        $this->configureStorefront($company, $branch);

        $response = $this->postJson(route('storefront.checkout.whatsapp'), [
            'customer_name' => 'Diseñadora',
            'customer_phone' => '70000000',
            'fulfillment' => 'pickup',
            'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]],
        ]);

        $response->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('url', null)
            ->assertJsonPath('subtotal', '71.00');
        $this->assertStringNotContainsString('wa.me', json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
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

        $this->postJson(route('storefront.checkout.whatsapp'), [
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

        $this->postJson(route('storefront.checkout.whatsapp'), [
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

    public function test_checkout_requires_an_official_configured_whatsapp_number(): void
    {
        [$company, $branch, , $product] = $this->storefront('Masa & Maña', null);
        $variant = ProductVariant::factory()->for($product)->create();
        $this->configureStorefront($company, $branch);
        config()->set('storefront.demo_mode', false);

        $this->postJson(route('storefront.checkout.whatsapp'), [
            'customer_name' => 'Cliente',
            'customer_phone' => '70000000',
            'fulfillment' => 'pickup',
            'items' => [['variant_ulid' => $variant->ulid, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('storefront');
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

        $this->get(route('storefront.menu'))
            ->assertOk()
            ->assertSee('Sin stock');

        $this->postJson(route('storefront.checkout.whatsapp'), [
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
