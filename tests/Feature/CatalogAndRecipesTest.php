<?php

namespace Tests\Feature;

use App\Actions\UpdateRecipeAction;
use App\Enums\MembershipRole;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogAndRecipesTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_administration_separates_prepared_products_from_direct_sales(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $ingredient = $this->ingredientFor($company);
        $preparedProduct = Product::factory()->for($company)->create(['name' => 'Pizza Pepperoni', 'type' => ProductType::Pizza]);
        $prepared = ProductVariant::factory()->for($company)->for($preparedProduct)->create(['name' => 'Mediana', 'requires_preparation' => true]);
        app(UpdateRecipeAction::class)->execute($company, $prepared, [[
            'ingredient_id' => $ingredient->id,
            'quantity' => '100.000',
        ]]);
        $directProduct = Product::factory()->for($company)->create(['name' => 'Coca-Cola', 'type' => ProductType::Beverage]);
        ProductVariant::factory()->for($company)->for($directProduct)->create(['name' => '500 ml', 'requires_preparation' => false]);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('Configura qué ingredientes consume cada producto preparado.')
            ->assertSee('+ Nueva receta')
            ->assertSee('Productos preparados')
            ->assertSee('Editar receta')
            ->assertSee('Venta directa — no requiere receta')
            ->assertDontSee('>Crear<', false);
    }

    public function test_new_recipe_flow_lists_only_eligible_prepared_variants(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $ingredient = $this->ingredientFor($company);
        $preparedProduct = Product::factory()->for($company)->create(['name' => 'Pizza Hawaiana', 'type' => ProductType::Pizza]);
        $prepared = ProductVariant::factory()->for($company)->for($preparedProduct)->create(['name' => 'Familiar', 'requires_preparation' => true]);
        $directProduct = Product::factory()->for($company)->create(['name' => 'Gaseosa', 'type' => ProductType::Beverage]);
        $direct = ProductVariant::factory()->for($company)->for($directProduct)->create(['name' => 'Botella', 'requires_preparation' => false]);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('recipes.create'))
            ->assertOk()
            ->assertSee('Pizza Hawaiana')
            ->assertSee('Familiar')
            ->assertDontSee('Gaseosa');

        $this->actingInContext($owner, $company, $branch)
            ->get(route('recipes.edit', $direct->ulid))
            ->assertNotFound();

        $this->actingInContext($owner, $company, $branch)
            ->put(route('recipes.update', $direct->ulid), [
                'name' => 'No corresponde',
                'is_active' => '1',
                'items' => [[
                    'ingredient_id' => $ingredient->id,
                    'component_type' => 'topping',
                    'quantity' => '1.000',
                ]],
            ])
            ->assertSessionHasErrors(['variant' => 'Los productos de venta directa no requieren receta.']);
        $this->assertDatabaseMissing('recipes', ['product_variant_id' => $direct->id]);

        $this->actingInContext($owner, $company, $branch)
            ->get(route('recipes.edit', $prepared->ulid))
            ->assertOk()
            ->assertSee('Crear receta')
            ->assertSee('unidad base');

        $this->actingInContext($owner, $company, $branch)
            ->put(route('recipes.update', $prepared->ulid), [
                'name' => 'Receta Hawaiana Familiar',
                'is_active' => '1',
                'items' => [[
                    'ingredient_id' => $ingredient->id,
                    'component_type' => 'topping',
                    'quantity' => '80.000',
                ]],
            ])
            ->assertRedirect(route('recipes.index'));

        $this->assertDatabaseHas('recipes', ['product_variant_id' => $prepared->id, 'name' => 'Receta Hawaiana Familiar', 'is_active' => true]);
    }

    public function test_pizza_sizes_generate_compatible_internal_keys_without_exposing_them(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $payload = [
            'type' => ProductType::Pizza->value,
            'is_active' => '1',
            'variants' => [[
                'name' => 'Pequeña Especial',
                'price' => '25.00',
                'requires_preparation' => '1',
                'is_active' => '1',
                'sort_order' => '0',
            ]],
        ];

        $response = $this->actingInContext($owner, $company, $branch)->post(route('products.store'), [
            ...$payload,
            'name' => 'Pizza Pepperoni',
        ]);
        $product = Product::query()->where('name', 'Pizza Pepperoni')->firstOrFail();

        $response->assertRedirect(route('recipes.create', ['product' => $product->ulid]));
        $this->assertSame('pequena-especial', $product->variants()->value('size_key'));

        $this->actingInContext($owner, $company, $branch)->post(route('products.store'), [
            ...$payload,
            'name' => 'Pizza Hawaiana',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            Product::query()->where('name', 'Pizza Pepperoni')->firstOrFail()->variants()->value('size_key'),
            Product::query()->where('name', 'Pizza Hawaiana')->firstOrFail()->variants()->value('size_key'),
        );
        $this->actingInContext($owner, $company, $branch)->get(route('products.create'))
            ->assertOk()
            ->assertSee('Tamaño o presentación')
            ->assertDontSee('Clave de tamaño')
            ->assertDontSee('size_key');
    }

    public function test_duplicate_size_message_and_empty_recipe_state_are_operational(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();

        $this->actingInContext($owner, $company, $branch)->post(route('products.store'), [
            'name' => 'Pizza repetida',
            'type' => ProductType::Pizza->value,
            'is_active' => '1',
            'variants' => [
                ['name' => 'Familiar', 'price' => '50.00', 'requires_preparation' => '1', 'is_active' => '1', 'sort_order' => '0'],
                ['name' => 'Familiar', 'price' => '60.00', 'requires_preparation' => '1', 'is_active' => '1', 'sort_order' => '1'],
            ],
        ])->assertSessionHasErrors(['variants.0.name' => 'Ese tamaño o presentación ya está agregado. Usa nombres diferentes.']);

        $this->actingInContext($owner, $company, $branch)->get(route('recipes.create'))
            ->assertOk()
            ->assertSee('No hay tamaños pendientes de receta.')
            ->assertSee('Ir a Productos');
    }

    public function test_editing_recipe_preserves_the_existing_single_recipe_record(): void
    {
        $company = Company::factory()->create();
        $variant = $this->variantFor($company);
        $firstIngredient = $this->ingredientFor($company);
        $secondIngredient = $this->ingredientFor($company);
        $recipe = app(UpdateRecipeAction::class)->execute($company, $variant, [[
            'ingredient_id' => $firstIngredient->id,
            'quantity' => '1.000',
        ]]);

        $updated = app(UpdateRecipeAction::class)->execute($company, $variant, [[
            'ingredient_id' => $secondIngredient->id,
            'quantity' => '2.000',
        ]], 'Receta actualizada');

        $this->assertSame($recipe->id, $updated->id);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseMissing('recipe_items', ['recipe_id' => $recipe->id, 'ingredient_id' => $firstIngredient->id]);
        $this->assertDatabaseHas('recipe_items', ['recipe_id' => $recipe->id, 'ingredient_id' => $secondIngredient->id, 'quantity' => 2]);
    }

    public function test_recipe_management_respects_permissions_and_company_isolation(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $kitchen = User::factory()->create();
        Membership::factory()->for($company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $otherCompany = Company::factory()->create();
        $otherVariant = $this->variantFor($otherCompany);

        $this->actingInContext($kitchen, $company, $branch)->get(route('recipes.index'))->assertOk();
        $this->actingInContext($kitchen, $company, $branch)->get(route('recipes.create'))->assertForbidden();
        $this->actingInContext($waiter, $company, $branch)->get(route('recipes.index'))->assertForbidden();
        $this->actingInContext($kitchen, $company, $branch)->get(route('recipes.edit', $otherVariant->ulid))->assertNotFound();
    }

    public function test_company_cannot_access_another_companies_category_or_product(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $ownerA = User::factory()->create();
        Membership::factory()->for($companyA)->for($ownerA)->owner()->create();
        $categoryB = Category::factory()->for($companyB)->create();
        $productB = Product::factory()->for($companyB)->create();

        $this->assertFalse(Gate::forUser($ownerA)->allows('view', $categoryB));
        $this->assertFalse(Gate::forUser($ownerA)->allows('view', $productB));
        $this->assertFalse(Gate::forUser($ownerA)->allows('update', $categoryB));
        $this->assertFalse(Gate::forUser($ownerA)->allows('update', $productB));
    }

    public function test_variant_and_product_must_belong_to_the_same_company(): void
    {
        $product = Product::factory()->create();
        $otherCompany = Company::factory()->create();

        $this->expectException(DomainException::class);

        ProductVariant::factory()
            ->for($product)
            ->for($otherCompany)
            ->create();
    }

    public function test_recipe_cannot_use_an_ingredient_from_another_company(): void
    {
        $company = Company::factory()->create();
        $variant = $this->variantFor($company);
        $otherIngredient = $this->ingredientFor(Company::factory()->create());

        $this->expectException(ValidationException::class);

        app(UpdateRecipeAction::class)->execute($company, $variant, [[
            'ingredient_id' => $otherIngredient->getKey(),
            'quantity' => '1.000',
        ]]);
    }

    public function test_recipe_action_rejects_a_repeated_ingredient(): void
    {
        $company = Company::factory()->create();
        $variant = $this->variantFor($company);
        $ingredient = $this->ingredientFor($company);

        $this->expectException(ValidationException::class);

        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $ingredient->getKey(), 'quantity' => '1.000'],
            ['ingredient_id' => $ingredient->getKey(), 'quantity' => '2.000'],
        ]);
    }

    public function test_database_prevents_a_repeated_ingredient_in_the_same_recipe(): void
    {
        $company = Company::factory()->create();
        $recipe = Recipe::factory()->for($this->variantFor($company))->create();
        $ingredient = $this->ingredientFor($company);
        $attributes = [
            'company_id' => $company->getKey(),
            'recipe_id' => $recipe->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'quantity' => '1.000',
        ];
        RecipeItem::query()->create($attributes);

        $this->expectException(QueryException::class);

        RecipeItem::query()->create($attributes);
    }

    public function test_recipe_quantities_must_be_positive(): void
    {
        $company = Company::factory()->create();
        $recipe = Recipe::factory()->for($this->variantFor($company))->create();
        $ingredient = $this->ingredientFor($company);

        $this->expectException(DomainException::class);

        RecipeItem::query()->create([
            'company_id' => $company->getKey(),
            'recipe_id' => $recipe->getKey(),
            'ingredient_id' => $ingredient->getKey(),
            'quantity' => '0.000',
        ]);
    }

    public function test_each_variant_has_at_most_one_recipe(): void
    {
        $variant = ProductVariant::factory()->create();
        Recipe::factory()->for($variant)->create();

        $this->expectException(QueryException::class);

        Recipe::factory()->for($variant)->create();
    }

    public function test_owner_can_manage_own_catalog_but_another_companies_user_cannot(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        Membership::factory()->for($otherCompany)->for($otherOwner)->owner()->create();
        $category = Category::factory()->for($company)->create();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $category));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('update', $category));
    }

    public function test_demo_seeder_is_idempotent_for_catalog_and_recipes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstCounts = $this->catalogCounts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($firstCounts, $this->catalogCounts());
        $this->assertSame([
            'units' => 5,
            'categories' => 4,
            'ingredients' => 10,
            'products' => 2,
            'variants' => 4,
            'recipes' => 3,
            'recipe_items' => 12,
        ], $firstCounts);
        $this->assertDatabaseCount('packaging_rules', 3);
    }

    private function variantFor(Company $company): ProductVariant
    {
        $product = Product::factory()->for($company)->create();

        return ProductVariant::factory()->for($product)->create();
    }

    private function ingredientFor(Company $company): Ingredient
    {
        $unit = Unit::factory()->for($company)->create();

        return Ingredient::factory()->for($unit)->create();
    }

    /** @return array<string, int> */
    private function catalogCounts(): array
    {
        return [
            'units' => Unit::query()->count(),
            'categories' => Category::query()->count(),
            'ingredients' => Ingredient::query()->count(),
            'products' => Product::query()->count(),
            'variants' => ProductVariant::query()->count(),
            'recipes' => Recipe::query()->count(),
            'recipe_items' => RecipeItem::query()->count(),
        ];
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
