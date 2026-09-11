<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MenuAvailabilityStatus;
use App\Enums\ProductType;
use App\Enums\RecipeComponentType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\MenuAvailabilityService;
use App\Services\SellableAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_operational_roles_can_view_the_menu_and_kitchen_cannot(): void
    {
        [$company, $branch] = $this->context();

        foreach ([MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Cashier, MembershipRole::Waiter] as $role) {
            $user = $this->member($company, $role);
            $this->actingInContext($user, $company, $branch)
                ->get(route('menu-availability.index'))
                ->assertOk()
                ->assertSee('Menú')
                ->assertSee('href="'.route('menu-availability.index').'"', false);
        }

        $kitchen = $this->member($company, MembershipRole::Kitchen);
        $this->actingInContext($kitchen, $company, $branch)
            ->get(route('menu-availability.index'))
            ->assertForbidden();
        $this->actingInContext($kitchen, $company, $branch)
            ->get(route('kitchen.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('menu-availability.index').'"', false);
    }

    public function test_catalog_reuses_real_availability_without_exposing_recipe_or_cost_data(): void
    {
        [$company, $branch] = $this->context();
        $cashier = $this->member($company, MembershipRole::Cashier);
        $waiter = $this->member($company, MembershipRole::Waiter);
        $catalog = $this->catalog($company, $branch);
        $otherCompany = Company::factory()->create();
        Product::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Producto ajeno']);
        $inactiveProduct = Product::factory()->for($company)->create(['name' => 'Producto inactivo', 'is_active' => false]);
        ProductVariant::factory()->for($inactiveProduct)->create(['company_id' => $company->id]);
        ProductVariant::factory()->for($catalog['pizza'])->create([
            'company_id' => $company->id,
            'name' => 'Presentación inactiva',
            'is_active' => false,
        ]);
        $untrackedDirect = Product::factory()->for($company)->create([
            'name' => 'Servicio de cortesía',
            'description' => 'Descripción privada del artículo',
            'type' => ProductType::Beverage,
        ]);
        ProductVariant::factory()->for($untrackedDirect)->create([
            'company_id' => $company->id,
            'name' => 'Vaso',
            'requires_preparation' => false,
        ]);
        $unconfiguredPizza = Product::factory()->for($company)->create([
            'name' => 'Pizza pendiente',
            'description' => 'Salsa secreta y queso reservado',
            'type' => ProductType::Pizza,
        ]);
        ProductVariant::factory()->for($unconfiguredPizza)->create([
            'company_id' => $company->id,
            'name' => 'Personal',
            'requires_preparation' => true,
        ]);
        $promotionProduct = Product::factory()->for($company)->create(['name' => 'Promoción privada']);
        $promotionVariant = ProductVariant::factory()->for($promotionProduct)->create(['company_id' => $company->id]);
        Promotion::factory()->for($promotionVariant, 'productVariant')->create(['company_id' => $company->id]);

        $expectedPersonal = app(SellableAvailabilityService::class)
            ->calculate($catalog['variants']['personal'], $branch)
            ->availableQuantity;
        $before = [
            InventoryStock::query()->count(),
            InventoryBatch::query()->count(),
            InventoryReservation::query()->count(),
            InventoryMovement::query()->count(),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $menu = app(MenuAvailabilityService::class)->catalog($company, $branch);
        $menuQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $pizza = collect($menu->products)->firstWhere('name', 'Hawaiana');
        $beverage = collect($menu->products)->firstWhere('name', 'Coca-Cola 500 ml');
        $untracked = collect($menu->products)->where('status', MenuAvailabilityStatus::Untracked);

        $this->assertNotNull($pizza);
        $this->assertNotNull($beverage);
        $this->assertSame(MenuAvailabilityStatus::Limited, $pizza->status);
        $this->assertSame(['10', '2', '0'], collect($pizza->variants)->pluck('availableQuantity')->all());
        $this->assertSame($expectedPersonal, $pizza->variants[0]->availableQuantity.'.000');
        $this->assertSame(['Piña'], $pizza->variants[1]->limitingComponents);
        $this->assertSame(['Piña'], $pizza->variants[2]->limitingComponents);
        $this->assertSame('7', $beverage->variants[0]->availableQuantity);
        $this->assertSame(MenuAvailabilityStatus::Available, $beverage->status);
        $this->assertCount(2, $untracked);
        $this->assertTrue($untracked->every(fn ($product): bool => $product->variants[0]->availableQuantity === null));
        $this->assertSame(['available' => 1, 'limited' => 1, 'unavailable' => 0, 'total' => 4], $menu->summary);
        $this->assertSame(['Bebidas', 'Pizzas'], collect($menu->categories)->pluck('name')->sort()->values()->all());
        $this->assertLessThanOrEqual(16, $menuQueryCount, 'The menu catalog must use bounded eager-loaded queries.');

        $response = $this->actingInContext($cashier, $company, $branch)
            ->get(route('menu-availability.index'))
            ->assertOk()
            ->assertSee('Hawaiana')
            ->assertSeeInOrder(['Personal', 'Bs 38,00', '10 disponibles'])
            ->assertSeeInOrder(['Mediana', 'Bs 70,00', '2 disponibles'])
            ->assertSeeInOrder(['Familiar', 'Bs 92,00', '0 disponibles'])
            ->assertSee('Sin stock')
            ->assertSee('Piña')
            ->assertSee('Coca-Cola 500 ml')
            ->assertSee('7 disponibles')
            ->assertSee('Pizzas')
            ->assertSee('Bebidas')
            ->assertSee('Servicio de cortesía')
            ->assertSee('Pizza pendiente')
            ->assertSee('Sin control de stock')
            ->assertDontSee('Producto ajeno')
            ->assertDontSee('Producto inactivo')
            ->assertDontSee('Presentación inactiva')
            ->assertDontSee('Promoción privada')
            ->assertDontSee('Salsa de tomate, mozzarella, jamón y piña.')
            ->assertDontSee('Descripción privada del artículo')
            ->assertDontSee('Salsa secreta y queso reservado')
            ->assertDontSee('Salsa confidencial')
            ->assertDontSee('Fórmula confidencial')
            ->assertDontSee('123.000')
            ->assertDontSee('9.123456')
            ->assertDontSee('Costo')
            ->assertDontSee('Configurar recetas')
            ->assertDontSee('Agregar a venta');

        $this->assertSame(200, $response->getStatusCode());
        $this->actingInContext($waiter, $company, $branch)
            ->get(route('menu-availability.index'))
            ->assertOk()
            ->assertDontSee('Salsa de tomate, mozzarella, jamón y piña.')
            ->assertDontSee('Descripción privada del artículo')
            ->assertDontSee('Salsa secreta y queso reservado');
        $this->assertSame($before, [
            InventoryStock::query()->count(),
            InventoryBatch::query()->count(),
            InventoryReservation::query()->count(),
            InventoryMovement::query()->count(),
        ]);
    }

    private function catalog(Company $company, Branch $branch): array
    {
        $unit = Unit::factory()->create(['company_id' => $company->id, 'symbol' => 'u']);
        $pizzas = Category::factory()->for($company)->create(['name' => 'Pizzas', 'sort_order' => 1]);
        $beverages = Category::factory()->for($company)->create(['name' => 'Bebidas', 'sort_order' => 0]);
        $pizza = Product::factory()->for($company)->for($pizzas, 'category')->create([
            'name' => 'Hawaiana',
            'description' => 'Salsa de tomate, mozzarella, jamón y piña.',
            'type' => ProductType::Pizza,
        ]);
        $pineapple = Ingredient::factory()->for($unit)->create(['company_id' => $company->id, 'name' => 'Piña']);
        $secretSauce = Ingredient::factory()->for($unit)->create(['company_id' => $company->id, 'name' => 'Salsa confidencial']);
        $pineappleItem = $this->ingredientStock($company, $branch, $unit, $pineapple, '100.000', '9.123456');
        $secretSauceItem = $this->ingredientStock($company, $branch, $unit, $secretSauce, '10000.000', '8.654321');
        $variants = [];

        foreach ([
            'personal' => ['Personal', '38.00', '10.000'],
            'medium' => ['Mediana', '70.00', '40.000'],
            'family' => ['Familiar', '92.00', '123.000'],
        ] as $key => [$name, $price, $required]) {
            $variant = ProductVariant::factory()->for($pizza)->create([
                'company_id' => $company->id,
                'name' => $name,
                'size_key' => $key,
                'price' => $price,
                'requires_preparation' => true,
                'sort_order' => count($variants),
            ]);
            $recipe = Recipe::factory()->for($variant, 'productVariant')->create([
                'company_id' => $company->id,
                'name' => 'Fórmula confidencial '.$name,
            ]);
            RecipeItem::factory()->for($recipe)->for($pineapple)->create([
                'company_id' => $company->id,
                'component_type' => RecipeComponentType::Topping,
                'quantity' => $required,
            ]);
            RecipeItem::factory()->for($recipe)->for($secretSauce)->create([
                'company_id' => $company->id,
                'component_type' => RecipeComponentType::Base,
                'quantity' => '1.000',
            ]);
            $variants[$key] = $variant;
        }

        $beverage = Product::factory()->for($company)->for($beverages, 'category')->create([
            'name' => 'Coca-Cola 500 ml',
            'description' => null,
            'type' => ProductType::Beverage,
        ]);
        $bottle = ProductVariant::factory()->for($beverage)->create([
            'company_id' => $company->id,
            'name' => '500 ml',
            'price' => '10.00',
            'requires_preparation' => false,
        ]);
        $directItem = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'product_variant_id' => $bottle->id,
            'name' => 'Coca-Cola 500 ml',
            'is_active' => true,
        ]);
        $this->stock($company, $branch, $directItem, '7.000', '3.000000');

        return compact('pizza', 'variants', 'pineappleItem', 'secretSauceItem');
    }

    private function ingredientStock(Company $company, Branch $branch, Unit $unit, Ingredient $ingredient, string $quantity, string $cost): InventoryItem
    {
        $item = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'name' => $ingredient->name,
            'is_active' => true,
        ]);
        $this->stock($company, $branch, $item, $quantity, $cost);

        return $item;
    }

    private function stock(Company $company, Branch $branch, InventoryItem $item, string $quantity, string $cost): void
    {
        InventoryStock::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => $quantity,
            'average_cost' => $cost,
        ]);
        InventoryBatch::factory()->for($company)->for($branch)->for($item)->create([
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_cost' => $cost,
        ]);
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();

        return [$company, $branch];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
