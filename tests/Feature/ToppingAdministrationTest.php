<?php

namespace Tests\Feature;

use App\Actions\SaveToppingAction;
use App\Enums\MembershipRole;
use App\Enums\ProductModifierPurpose;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderPosCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToppingAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_creates_price_only_topping_with_decimal_price(): void
    {
        [$company, $branch, $owner] = $this->context();

        $this->asUser($owner, $company, $branch)->get(route('toppings.create'))
            ->assertOk()->assertSee('Precio adicional general')->assertSee('Artículo de inventario opcional');

        $this->asUser($owner, $company, $branch)->post(route('toppings.store'), [
            'name' => 'Aceitunas',
            'description' => 'Cargo sin consumo físico',
            'price_delta' => '5.25',
            'inventory_item_ulid' => '',
            'default_quantity' => '',
            'sort_order' => '4',
            'is_active' => '1',
            'size_rules' => [],
        ])->assertRedirect(route('toppings.index'))->assertSessionHasNoErrors();

        $topping = ModifierOption::query()->where('name', 'Aceitunas')->firstOrFail();
        $this->assertSame($company->id, $topping->company_id);
        $this->assertSame(ProductModifierPurpose::ToppingCatalog, $topping->modifier->purpose);
        $this->assertSame('5.25', $topping->price_delta);
        $this->assertNull($topping->inventory_item_id);
        $this->assertNull($topping->quantity);
        $this->assertNotNull($topping->ulid);
    }

    public function test_owner_creates_inventory_topping_with_quantities_by_size_then_edits_and_toggles_it(): void
    {
        [$company, $branch, $owner] = $this->context();
        $this->pizzaSizes($company, ['Personal', 'Mediana', 'Familiar']);
        $item = $this->ingredientItem($company, 'Tocino');

        $this->asUser($owner, $company, $branch)->post(route('toppings.store'), [
            'name' => 'Tocino',
            'description' => 'Porción extra',
            'price_delta' => '5.00',
            'inventory_item_ulid' => $item->ulid,
            'default_quantity' => '',
            'sort_order' => '2',
            'is_active' => '1',
            'size_rules' => [
                ['size_key' => 'personal', 'price_delta' => '', 'quantity' => '20.000'],
                ['size_key' => 'mediana', 'price_delta' => '6.50', 'quantity' => '30.000'],
                ['size_key' => 'familiar', 'price_delta' => '', 'quantity' => '40.000'],
            ],
        ])->assertRedirect(route('toppings.index'))->assertSessionHasNoErrors();

        $topping = ModifierOption::query()->where('name', 'Tocino')->firstOrFail();
        $this->assertSame($item->id, $topping->inventory_item_id);
        $this->assertSame($item->ingredient_id, $topping->ingredient_id);
        $this->assertSame($item->unit_id, $topping->unit_id);
        $this->assertNull($topping->quantity);
        $this->assertSame([
            'familiar' => '40.000',
            'mediana' => '30.000',
            'personal' => '20.000',
        ], $topping->sizeRules()->pluck('quantity', 'size_key')->all());
        $this->assertSame('6.50', $topping->sizeRules()->where('size_key', 'mediana')->value('price_delta'));

        $this->asUser($owner, $company, $branch)->put(route('toppings.update', $topping->ulid), [
            'name' => 'Tocino crocante',
            'description' => 'Actualizado',
            'price_delta' => '5.75',
            'inventory_item_ulid' => $item->ulid,
            'default_quantity' => '25.500',
            'sort_order' => '1',
            'is_active' => '1',
            'size_rules' => [
                ['size_key' => 'personal', 'price_delta' => '', 'quantity' => '22.000'],
                ['size_key' => 'mediana', 'price_delta' => '', 'quantity' => ''],
                ['size_key' => 'familiar', 'price_delta' => '', 'quantity' => ''],
            ],
        ])->assertRedirect(route('toppings.index'))->assertSessionHasNoErrors();

        $topping->refresh();
        $this->assertSame('Tocino crocante', $topping->name);
        $this->assertSame('5.75', $topping->price_delta);
        $this->assertSame('25.500', $topping->quantity);
        $this->assertSame(['personal'], $topping->sizeRules()->pluck('size_key')->all());

        $this->asUser($owner, $company, $branch)->post(route('toppings.toggle', $topping->ulid))
            ->assertRedirect();
        $this->assertFalse($topping->refresh()->is_active);
        $this->asUser($owner, $company, $branch)->post(route('toppings.toggle', $topping->ulid));
        $this->assertTrue($topping->refresh()->is_active);
    }

    public function test_toppings_are_isolated_and_foreign_inventory_is_rejected(): void
    {
        [$company, $branch, $owner] = $this->context();
        [$foreignCompany] = $this->context();
        $foreignItem = $this->ingredientItem($foreignCompany, 'Jamón ajeno');
        $foreignTopping = app(SaveToppingAction::class)->execute($foreignCompany, [
            'name' => 'Champiñones ajenos', 'description' => null, 'price_delta' => '4.00',
            'inventory_item_ulid' => null, 'default_quantity' => null, 'sort_order' => 0,
            'is_active' => true, 'size_rules' => [],
        ]);

        $this->asUser($owner, $company, $branch)->get(route('toppings.index'))
            ->assertOk()->assertDontSee('Champiñones ajenos');
        $this->asUser($owner, $company, $branch)->get(route('toppings.edit', $foreignTopping->ulid))->assertNotFound();
        $this->asUser($owner, $company, $branch)->put(route('toppings.update', $foreignTopping->ulid), [
            'name' => 'Intento ajeno', 'price_delta' => '4.00', 'sort_order' => 0,
            'is_active' => '1', 'size_rules' => [],
        ])->assertNotFound();
        $this->asUser($owner, $company, $branch)->post(route('toppings.toggle', $foreignTopping->ulid))->assertNotFound();

        $this->asUser($owner, $company, $branch)->post(route('toppings.store'), [
            'name' => 'Jamón inválido', 'price_delta' => '3.00', 'inventory_item_ulid' => $foreignItem->ulid,
            'default_quantity' => '10.000', 'sort_order' => 0, 'is_active' => '1', 'size_rules' => [],
        ])->assertSessionHasErrors('inventory_item_ulid');
        $this->assertDatabaseMissing('modifier_options', ['company_id' => $company->id, 'name' => 'Jamón inválido']);
    }

    public function test_catalog_view_permission_does_not_grant_topping_management(): void
    {
        [$company, $branch] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier);

        $this->asUser($cashier, $company, $branch)->get(route('toppings.index'))->assertOk();
        $this->asUser($cashier, $company, $branch)->get(route('toppings.create'))->assertForbidden();
        $this->asUser($cashier, $company, $branch)->post(route('toppings.store'), [
            'name' => 'Extra queso', 'price_delta' => '5.00', 'sort_order' => 0, 'is_active' => '1', 'size_rules' => [],
        ])->assertForbidden();
    }

    public function test_administrative_toppings_are_not_exposed_to_the_pos_before_sales_integration(): void
    {
        [$company, $branch] = $this->context();
        app(SaveToppingAction::class)->execute($company, [
            'name' => 'Extra queso futuro', 'description' => null, 'price_delta' => '5.00',
            'inventory_item_ulid' => null, 'default_quantity' => null, 'sort_order' => 0,
            'is_active' => true, 'size_rules' => [],
        ]);

        $catalog = app(OrderPosCatalogService::class)->forOrderScreen($company, $branch);

        $this->assertTrue($catalog['modifierOptions']->isEmpty());
    }

    private function context(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $branch = Branch::factory()->for($company)->create(['is_active' => true]);
        $owner = $this->member($company, MembershipRole::Owner);

        return [$company, $branch, $owner];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role, 'is_active' => true]);

        return $user;
    }

    private function asUser(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }

    private function pizzaSizes(Company $company, array $names): void
    {
        $product = Product::factory()->for($company)->create(['type' => ProductType::Pizza]);
        foreach ($names as $index => $name) {
            ProductVariant::factory()->for($company)->for($product)->create([
                'name' => $name, 'size_key' => str($name)->slug()->toString(), 'sort_order' => $index,
            ]);
        }
    }

    private function ingredientItem(Company $company, string $name): InventoryItem
    {
        $unit = Unit::factory()->for($company)->create(['type' => UnitType::Weight, 'symbol' => 'g']);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);

        return InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $ingredient->id, 'product_variant_id' => null, 'name' => $name,
        ]);
    }
}
