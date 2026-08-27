<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_is_required_and_valid_credentials_open_the_dashboard(): void
    {
        [$company, $branch, $owner] = $this->context();
        $owner->update(['password' => Hash::make('secreto-seguro')]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $owner->email, 'password' => 'secreto-seguro'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($owner);
        $this->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get('/dashboard')->assertOk()->assertSee('Inventario total');
    }

    public function test_owner_can_see_the_real_dashboard(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);
        Product::factory()->for($company)->create(['name' => 'Pizza propia']);
        Ingredient::factory()->for($company)->for($unit)->create(['name' => 'Queso propio']);

        $this->asUser($owner, $company, $branch)->get(route('dashboard'))
            ->assertOk()->assertSee('Resumen del día')->assertSee('Productos')->assertSee('Ingredientes');
    }

    public function test_dashboard_loads_when_inventory_stock_has_no_expiring_batch(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();
        $item = InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Ingrediente sin lote',
        ]);
        InventoryStock::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '2.000',
            'minimum_quantity' => '5.000',
        ]);

        $this->asUser($owner, $company, $branch)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ingrediente sin lote')
            ->assertSee('Sin vencimiento próximo');
    }

    public function test_dashboard_labels_a_batch_without_expiration(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();
        $item = InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Ingrediente sin caducidad',
        ]);
        InventoryStock::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '2.000',
            'minimum_quantity' => '5.000',
        ]);
        InventoryBatch::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity_received' => '2.000',
            'quantity_remaining' => '2.000',
            'expires_at' => null,
        ]);

        $this->asUser($owner, $company, $branch)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ingrediente sin caducidad')
            ->assertSee('Sin caducidad');
    }

    public function test_dashboard_attention_renders_human_messages_without_blade_source(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);

        $outIngredient = Ingredient::factory()->for($company)->for($unit)->create();
        InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $outIngredient->id,
            'name' => 'Ingrediente agotado',
        ]);

        $lowIngredient = Ingredient::factory()->for($company)->for($unit)->create();
        $lowItem = InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $lowIngredient->id,
            'name' => 'Ingrediente con stock bajo',
        ]);
        InventoryStock::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $lowItem->id,
            'quantity' => '2.000',
            'minimum_quantity' => '5.000',
        ]);

        $expiringIngredient = Ingredient::factory()->for($company)->for($unit)->create();
        $expiringItem = InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $expiringIngredient->id,
            'name' => 'Ingrediente por vencer',
        ]);
        InventoryStock::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $expiringItem->id,
            'quantity' => '10.000',
            'minimum_quantity' => '1.000',
        ]);
        InventoryBatch::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $expiringItem->id,
            'quantity_received' => '10.000',
            'quantity_remaining' => '10.000',
            'expires_at' => now('America/La_Paz')->addDays(3)->toDateString(),
        ]);

        $pizza = Product::factory()->for($company)->create([
            'name' => 'Pizza sin receta completa',
            'type' => ProductType::Pizza,
        ]);
        $variant = ProductVariant::factory()->for($company)->for($pizza)->create([
            'name' => 'Mediana',
            'requires_preparation' => true,
        ]);
        Recipe::factory()->for($company)->for($variant)->create(['is_active' => true]);

        $response = $this->asUser($owner, $company, $branch)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Agotado')
            ->assertSee('Stock bajo')
            ->assertSee('Próximo a vencer')
            ->assertSee('Sin vencimiento próximo')
            ->assertSee('Receta incompleta')
            ->assertSee('Ver inventario')
            ->assertDontSee('@if', false)
            ->assertDontSee('@elseif', false)
            ->assertDontSee('@else', false)
            ->assertDontSee('App\\Enums', false)
            ->assertDontSee('if($item->expiration_status', false);
    }

    public function test_company_resources_are_not_opened_from_another_company(): void
    {
        [$company, $branch, $owner] = $this->context();
        $foreign = Company::factory()->create();
        $foreignProduct = Product::factory()->for($foreign)->create();
        $foreignIngredient = Ingredient::factory()->for($foreign)->for($this->unit($foreign))->create();

        $this->asUser($owner, $company, $branch)->get(route('products.edit', $foreignProduct->ulid))->assertNotFound();
        $this->asUser($owner, $company, $branch)->get(route('ingredients.edit', $foreignIngredient->ulid))->assertNotFound();
    }

    public function test_product_and_ingredient_indexes_only_show_the_active_company(): void
    {
        [$company, $branch, $owner] = $this->context();
        $foreign = Company::factory()->create();
        Product::factory()->for($company)->create(['name' => 'Producto visible']);
        Product::factory()->for($foreign)->create(['name' => 'Producto oculto']);
        Ingredient::factory()->for($company)->for($this->unit($company))->create(['name' => 'Ingrediente visible']);
        Ingredient::factory()->for($foreign)->for($this->unit($foreign))->create(['name' => 'Ingrediente oculto']);

        $this->asUser($owner, $company, $branch)->get(route('products.index'))
            ->assertOk()->assertSee('Producto visible')->assertDontSee('Producto oculto');
        $this->asUser($owner, $company, $branch)->get(route('ingredients.index'))
            ->assertOk()->assertSee('Ingrediente visible')->assertDontSee('Ingrediente oculto');
    }

    public function test_recipe_form_updates_the_recipe_through_the_domain_action(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();
        $product = Product::factory()->for($company)->create(['type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create();

        $this->asUser($owner, $company, $branch)->put(route('recipes.update', $variant->ulid), [
            'name' => 'Receta UI', 'is_active' => '1',
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => '125.500']],
        ])->assertRedirect(route('recipes.index'));

        $this->assertDatabaseHas('recipes', ['company_id' => $company->id, 'product_variant_id' => $variant->id, 'name' => 'Receta UI']);
        $this->assertDatabaseHas('recipe_items', ['ingredient_id' => $ingredient->id, 'quantity' => 125.5]);
    }

    public function test_inventory_operations_require_permission_and_owner_can_register_opening_stock(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();
        $item = InventoryItem::factory()->for($company)->for($unit)->create(['ingredient_id' => $ingredient->id]);
        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $payload = ['operation' => 'opening', 'quantity' => '10.000', 'unit_id' => $unit->id, 'unit_cost' => '2.000000'];

        $this->asUser($waiter, $company, $branch)->post(route('inventory.operate', $item->ulid), $payload)->assertForbidden();
        $this->asUser($owner, $company, $branch)->post(route('inventory.operate', $item->ulid), $payload)->assertRedirect();
        $this->assertDatabaseHas('inventory_stocks', ['inventory_item_id' => $item->id, 'quantity' => 10]);
    }

    public function test_purchase_draft_can_be_created_and_posted_but_not_edited_afterward(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = $this->unit($company);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create();
        $item = InventoryItem::factory()->for($company)->for($unit)->create(['ingredient_id' => $ingredient->id]);
        $payload = [
            'supplier_name' => 'Proveedor UI', 'document_number' => 'UI-001',
            'purchased_at' => '2026-08-20T10:30', 'notes' => 'Compra desde formulario',
            'items' => [['inventory_item_id' => $item->id, 'quantity' => '5.000', 'input_unit_id' => $unit->id, 'unit_cost' => '3.000000']],
        ];

        $this->asUser($owner, $company, $branch)->post(route('purchases.store'), $payload)->assertRedirect();
        $purchase = Purchase::query()->where('document_number', 'UI-001')->firstOrFail();
        $this->assertSame(PurchaseStatus::Draft, $purchase->status);

        $this->asUser($owner, $company, $branch)->post(route('purchases.post', $purchase->ulid))->assertRedirect();
        $this->assertSame(PurchaseStatus::Posted, $purchase->refresh()->status);
        $this->asUser($owner, $company, $branch)->get(route('purchases.edit', $purchase->ulid))->assertStatus(409);
    }

    public function test_last_owner_remains_protected_from_the_users_screen(): void
    {
        [$company, $branch, $owner, $membership] = $this->context();

        $this->asUser($owner, $company, $branch)->put(route('memberships.update', $membership->id), [
            'role' => MembershipRole::Admin->value,
        ])->assertSessionHasErrors('membership');

        $membership->refresh();
        $this->assertTrue($membership->is_active);
        $this->assertSame(MembershipRole::Owner, $membership->role);
    }

    private function context(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $branch = Branch::factory()->for($company)->create(['is_active' => true]);
        $owner = User::factory()->create();
        $membership = Membership::factory()->for($company)->for($owner)->owner()->create();

        return [$company, $branch, $owner, $membership];
    }

    private function unit(Company $company): Unit
    {
        return Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);
    }

    private function asUser(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
