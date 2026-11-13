<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\StorefrontCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('product_images');
    }

    public function test_upload_replace_remove_and_catalog_url_preserve_variants_prices_and_stock(): void
    {
        [$company, $branch, $owner] = $this->context();
        $category = Category::factory()->for($company)->create(['is_active' => true]);
        config()->set('storefront.visibility', ['category_names' => [], 'category_ulids' => [$category->ulid], 'product_names' => [], 'product_ulids' => []]);
        $unit = Unit::factory()->for($company)->create();
        $payload = $this->payload($category->id, $unit->id);
        $this->asContext($owner, $company, $branch)->post(route('products.store'), [
            ...$payload, 'image' => UploadedFile::fake()->image('untrusted.jpg'),
        ])->assertSessionHasNoErrors();

        $product = Product::query()->where('name', 'Pizza imagen')->firstOrFail();
        $old = $product->image_path;
        Storage::disk('product_images')->assertExists($old);
        $variants = $product->variants()->orderBy('sort_order')->get();
        $item = $variants[0]->inventoryItem()->firstOrFail();
        InventoryStock::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => '17.000']);
        foreach ($variants as $i => $variant) {
            $payload['variants'][$i]['ulid'] = $variant->ulid;
        }

        $catalogProduct = app(StorefrontCatalogService::class)->catalog($company, $branch)['categories']->first()->products->first();
        $this->assertSame($product->image_url, $catalogProduct->image_url);
        $this->assertSame($product->image_url, $catalogProduct->toArray()['image_url']);
        $this->assertCount(2, $catalogProduct->variants);

        $this->asContext($owner, $company, $branch)->put(route('products.update', $product->ulid), [
            ...$payload, 'image' => UploadedFile::fake()->image('replacement.png'),
        ])->assertSessionHasNoErrors();
        $new = $product->fresh()->image_path;
        $this->assertNotSame($old, $new);
        Storage::disk('product_images')->assertMissing($old);
        Storage::disk('product_images')->assertExists($new);
        $this->assertSame($variants->pluck('ulid')->all(), $product->variants()->orderBy('sort_order')->pluck('ulid')->all());
        $this->assertSame(['25.00', '40.00'], $product->variants()->orderBy('sort_order')->pluck('price')->all());
        $this->assertDatabaseHas('inventory_stocks', ['inventory_item_id' => $item->id, 'quantity' => '17.000']);
        $this->assertSame(1, InventoryItem::where('product_variant_id', $variants[0]->id)->count());

        $this->asContext($owner, $company, $branch)->put(route('products.update', $product->ulid), [
            ...$payload, 'remove_image' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Producto actualizado correctamente.');
        $this->assertNull($product->fresh()->image_path);
        Storage::disk('product_images')->assertMissing($new);
        $this->assertStringEndsWith('/images/product-placeholder.svg', $product->fresh()->image_url);
        $this->assertStringEndsWith('/images/product-placeholder.svg', app(StorefrontCatalogService::class)->catalog($company, $branch)['categories']->first()->products->first()->image_url);
    }

    public function test_pizza_sizes_share_one_webp_image(): void
    {
        [$company, $branch, $owner] = $this->context();
        $payload = $this->payload();
        $payload['type'] = ProductType::Pizza->value;
        $payload['image'] = UploadedFile::fake()->image('photo.webp');
        $this->asContext($owner, $company, $branch)->post(route('products.store'), $payload)->assertSessionHasNoErrors();
        $product = Product::where('name', 'Pizza imagen')->firstOrFail();
        $this->assertCount(2, $product->variants);
        $this->assertSame($product->image_url, $product->variants[0]->product->image_url);
        $this->assertSame($product->image_url, $product->variants[1]->product->image_url);
        Storage::disk('product_images')->assertExists($product->image_path);
    }

    public function test_invalid_and_oversized_files_are_rejected(): void
    {
        [$company, $branch, $owner] = $this->context();
        $payload = $this->payload();
        $this->asContext($owner, $company, $branch)->post(route('products.store'), [...$payload, 'image' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertSessionHasErrors('image');
        $this->asContext($owner, $company, $branch)->post(route('products.store'), [...$payload, 'image' => UploadedFile::fake()->image('large.jpg')->size(5121)])->assertSessionHasErrors('image');
        $this->assertDatabaseMissing('products', ['name' => 'Pizza imagen']);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_routes_require_authentication_and_manage_catalog_permission(): void
    {
        [$company, $branch] = $this->context();
        $product = Product::factory()->for($company)->create();
        $this->get(route('products.create'))->assertRedirect();
        $this->post(route('products.store'), [])->assertRedirect();
        $cashier = User::factory()->create();
        Membership::factory()->for($company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $this->asContext($cashier, $company, $branch)->get(route('products.create'))->assertForbidden();
        $this->asContext($cashier, $company, $branch)->get(route('products.edit', $product->ulid))->assertForbidden();
        $this->asContext($cashier, $company, $branch)->post(route('products.store'), [...$this->payload(), 'image' => UploadedFile::fake()->image('photo.jpg')])->assertForbidden();
        $this->asContext($cashier, $company, $branch)->put(route('products.update', $product->ulid), [...$this->payload(), 'remove_image' => '1'])->assertForbidden();
        $foreign = Product::factory()->create();
        $owner = Membership::where('company_id', $company->id)->firstOrFail()->user;
        $this->asContext($owner, $company, $branch)->put(route('products.update', $foreign->ulid), $this->payload())->assertNotFound();
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();

        return [$company, $branch, $owner];
    }

    private function asContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }

    private function payload(?int $category = null, ?int $unit = null): array
    {
        return ['name' => 'Pizza imagen', 'category_id' => $category, 'type' => ProductType::Beverage->value, 'is_active' => '1', 'variants' => [
            ['name' => 'Pequeña', 'price' => '25.00', 'track_stock' => $unit ? '1' : null, 'inventory_unit_id' => $unit, 'is_active' => '1', 'sort_order' => '0'],
            ['name' => 'Grande', 'price' => '40.00', 'is_active' => '1', 'sort_order' => '1'],
        ]];
    }
}
