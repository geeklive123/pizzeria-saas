<?php

namespace Tests\Feature;

use App\Actions\ApplyInventoryMovementAction;
use App\Actions\ProducePreparationAction;
use App\Actions\ReversePreparationProductionAction;
use App\Actions\SavePreparationAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\UnitType;
use App\Exceptions\InsufficientPreparationStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Preparation;
use App\Models\PreparationProduction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\PreparationAvailabilityService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PreparationProductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_it_creates_a_valid_preparation_and_rejects_duplicate_components(): void
    {
        $fixture = $this->fixture();
        $preparation = app(SavePreparationAction::class)->execute(
            $fixture['company'], $fixture['owner'], 'Masa', $fixture['output'], '8170', true,
            [
                ['inventory_item_id' => $fixture['flour']->id, 'quantity' => '5000'],
                ['inventory_item_id' => $fixture['water']->id, 'quantity' => '3000'],
                ['inventory_item_id' => $fixture['oil']->id, 'quantity' => '50'],
                ['inventory_item_id' => $fixture['salt']->id, 'quantity' => '100'],
                ['inventory_item_id' => $fixture['yeast']->id, 'quantity' => '20'],
            ],
        );

        $this->assertSame('Masa', $preparation->name);
        $this->assertSame('8170.000', $preparation->theoretical_yield);
        $this->assertCount(5, $preparation->components);
        $this->assertSame($fixture['gram']->id, $preparation->unit_id);

        $this->expectException(ValidationException::class);
        app(SavePreparationAction::class)->execute(
            $fixture['company'], $fixture['owner'], 'Duplicada', $fixture['output'], '8170', true,
            [
                ['inventory_item_id' => $fixture['flour']->id, 'quantity' => '5000'],
                ['inventory_item_id' => $fixture['flour']->id, 'quantity' => '100'],
            ],
        );
    }

    public function test_available_lots_use_effective_stock_and_identify_all_limiting_ingredients(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '12000', '0.010000');
        $this->stock($fixture, $fixture['water'], '6000', '0.002000');

        $result = app(PreparationAvailabilityService::class)->calculate($preparation, $fixture['branch']);

        $this->assertSame(2, $result->maximumLots);
        $this->assertSame('16340.000', $result->estimatedYield);
        $this->assertEqualsCanonicalizing(['Harina', 'Agua'], collect($result->limitingComponents)->pluck('name')->all());
    }

    public function test_available_lots_exclude_expired_batches_and_active_reservations(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '15000', '0.010000');
        $this->stock($fixture, $fixture['water'], '12000', '0.002000');
        $this->stock($fixture, $fixture['flour'], '5000', '0.010000', now()->subDay()->toDateString());
        $this->reserve($fixture, $fixture['water'], '3000');

        $result = app(PreparationAvailabilityService::class)->calculate($preparation, $fixture['branch']);

        $this->assertSame(3, $result->maximumLots);
        $water = collect($result->components)->firstWhere('name', 'Agua');
        $this->assertSame('9000.000', $water['available']);
    }

    public function test_it_produces_one_lot_with_fefo_traceability_yield_and_real_cost(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '5000', '0.010000');
        $this->stock($fixture, $fixture['water'], '3000', '0.002000');

        $production = app(ProducePreparationAction::class)->execute(
            $fixture['company'], $fixture['branch'], $preparation, 1, $fixture['owner'],
        );

        $this->assertSame('8170.000', $production->theoretical_yield);
        $this->assertSame('8170.000', $production->actual_yield);
        $this->assertSame('0.000', $production->yield_variance);
        $this->assertSame('56.000000', $production->total_cost);
        $this->assertSame('0.006854', $production->unit_cost);
        $this->assertSame('0.000', $this->quantity($fixture, $fixture['flour']));
        $this->assertSame('0.000', $this->quantity($fixture, $fixture['water']));
        $this->assertSame('8170.000', $this->quantity($fixture, $fixture['output']));
        $this->assertSame(3, $production->movements()->count());
        $this->assertSame(2, $production->movements()->where('type', InventoryMovementType::ProductionConsumption)->count());
        $this->assertNotEmpty($production->movements()->where('type', InventoryMovementType::ProductionConsumption)->first()->metadata['batch_allocations']);
    }

    public function test_it_produces_multiple_lots_and_accepts_an_audited_real_yield(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '15000', '0');
        $this->stock($fixture, $fixture['water'], '9000', '0');

        $production = app(ProducePreparationAction::class)->execute(
            $fixture['company'], $fixture['branch'], $preparation, 3, $fixture['owner'], '24000',
        );

        $this->assertSame(3, $production->lots);
        $this->assertSame('24510.000', $production->theoretical_yield);
        $this->assertSame('24000.000', $production->actual_yield);
        $this->assertSame('-510.000', $production->yield_variance);
        $this->assertSame('24000.000', $this->quantity($fixture, $fixture['output']));
        $this->assertSame('0.000000', $production->total_cost);
        $this->assertSame('0.000000', $production->unit_cost);
    }

    public function test_insufficient_stock_rolls_back_without_partial_movements(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '12000', '0.010000');
        $this->stock($fixture, $fixture['water'], '9000', '0.002000');
        $before = InventoryMovement::query()->count();

        try {
            app(ProducePreparationAction::class)->execute(
                $fixture['company'], $fixture['branch'], $preparation, 3, $fixture['owner'],
            );
            $this->fail('La producción debía fallar.');
        } catch (InsufficientPreparationStockException $exception) {
            $this->assertSame(2, $exception->maximumLots);
            $this->assertStringContainsString('Harina: 3000.000 g', $exception->getMessage());
        }

        $this->assertSame($before, InventoryMovement::query()->count());
        $this->assertSame(0, PreparationProduction::query()->count());
        $this->assertSame('12000.000', $this->quantity($fixture, $fixture['flour']));
        $this->assertSame('0.000', $this->quantity($fixture, $fixture['output']));
    }

    public function test_a_production_can_be_reversed_only_while_its_exact_output_is_unconsumed(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '5000', '0.010000');
        $this->stock($fixture, $fixture['water'], '3000', '0.002000');
        $production = app(ProducePreparationAction::class)->execute(
            $fixture['company'], $fixture['branch'], $preparation, 1, $fixture['owner'],
        );

        app(ReversePreparationProductionAction::class)->execute($production, $fixture['owner'], 'Registro equivocado');

        $this->assertNotNull($production->refresh()->reversed_at);
        $this->assertSame('5000.000', $this->quantity($fixture, $fixture['flour']));
        $this->assertSame('3000.000', $this->quantity($fixture, $fixture['water']));
        $this->assertSame('0.000', $this->quantity($fixture, $fixture['output']));
        $this->assertSame(3, InventoryMovement::query()->whereNotNull('reversal_of_id')->count());

        $fixture2 = $this->fixture();
        $preparation2 = $this->save($fixture2);
        $this->stock($fixture2, $fixture2['flour'], '5000', '0');
        $this->stock($fixture2, $fixture2['water'], '3000', '0');
        $production2 = app(ProducePreparationAction::class)->execute(
            $fixture2['company'], $fixture2['branch'], $preparation2, 1, $fixture2['owner'],
        );
        app(ApplyInventoryMovementAction::class)->execute(
            $fixture2['company'], $fixture2['branch'], $fixture2['output'], InventoryMovementType::AdjustmentOut,
            '1.000', null, $fixture2['owner'], reason: 'Consumo posterior',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya fue consumido');
        app(ReversePreparationProductionAction::class)->execute($production2, $fixture2['owner'], 'No corresponde');
    }

    public function test_production_enforces_company_branch_and_role_boundaries(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '5000', '0');
        $this->stock($fixture, $fixture['water'], '3000', '0');
        $foreign = Company::factory()->create(['is_active' => true]);
        $foreignBranch = Branch::factory()->for($foreign)->create(['is_active' => true]);

        try {
            app(ProducePreparationAction::class)->execute($fixture['company'], $foreignBranch, $preparation, 1, $fixture['owner']);
            $this->fail('Debía rechazarse la sucursal ajena.');
        } catch (AuthorizationException) {
            $this->assertSame(0, PreparationProduction::query()->count());
        }

        $waiter = $this->user($fixture['company'], MembershipRole::Waiter);
        $this->expectException(AuthorizationException::class);
        app(ProducePreparationAction::class)->execute($fixture['company'], $fixture['branch'], $preparation, 1, $waiter);
    }

    public function test_kitchen_can_view_and_produce_but_cannot_edit_or_reverse(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '5000', '0');
        $this->stock($fixture, $fixture['water'], '3000', '0');
        $kitchen = $this->user($fixture['company'], MembershipRole::Kitchen);

        $this->asUser($kitchen, $fixture)->get(route('preparations.show', $preparation->ulid))->assertOk();
        $this->asUser($kitchen, $fixture)->get(route('preparations.edit', $preparation->ulid))->assertForbidden();
        $this->asUser($kitchen, $fixture)->post(route('preparations.produce', $preparation->ulid), ['lots' => 1])->assertRedirect();
        $production = PreparationProduction::query()->firstOrFail();
        $this->asUser($kitchen, $fixture)->post(route('preparations.productions.reverse', [$preparation->ulid, $production->ulid]), ['reason' => 'No'])->assertForbidden();

        $cashier = $this->user($fixture['company'], MembershipRole::Cashier);
        $this->asUser($cashier, $fixture)->get(route('preparations.index'))->assertForbidden();
    }

    public function test_availability_and_production_are_isolated_between_branches_of_the_same_company(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '5000', '0');
        $this->stock($fixture, $fixture['water'], '3000', '0');
        $otherBranch = Branch::factory()->for($fixture['company'])->create(['is_active' => true]);

        $availability = app(PreparationAvailabilityService::class)->calculate($preparation, $otherBranch);

        $this->assertSame(0, $availability->maximumLots);
        $this->expectException(InsufficientPreparationStockException::class);
        app(ProducePreparationAction::class)->execute(
            $fixture['company'], $otherBranch, $preparation, 1, $fixture['owner'],
        );
    }

    public function test_ui_shows_current_maximum_and_rejects_a_quantity_above_it(): void
    {
        $fixture = $this->fixture();
        $preparation = $this->save($fixture);
        $this->stock($fixture, $fixture['flour'], '12000', '0');
        $this->stock($fixture, $fixture['water'], '6000', '0');

        $this->asUser($fixture['owner'], $fixture)->get(route('preparations.show', $preparation->ulid))
            ->assertOk()->assertSee('Puedes preparar hasta 2 lotes de Masa.')
            ->assertSee('Ingrediente limitante:')->assertSee('Harina')
            ->assertSee('data-maximum-lots="2"', false);

        $this->asUser($fixture['owner'], $fixture)->post(route('preparations.produce', $preparation->ulid), ['lots' => 3])
            ->assertSessionHasErrors('production');
        $this->assertSame(0, PreparationProduction::query()->count());
    }

    private function fixture(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $branch = Branch::factory()->for($company)->create(['is_active' => true]);
        $owner = $this->user($company, MembershipRole::Owner);
        $gram = Unit::factory()->for($company)->create(['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight]);

        return [
            'company' => $company, 'branch' => $branch, 'owner' => $owner, 'gram' => $gram,
            'flour' => $this->item($company, $gram, 'Harina'),
            'water' => $this->item($company, $gram, 'Agua'),
            'oil' => $this->item($company, $gram, 'Aceite de oliva'),
            'salt' => $this->item($company, $gram, 'Sal'),
            'yeast' => $this->item($company, $gram, 'Levadura'),
            'output' => $this->item($company, $gram, 'Masa'),
        ];
    }

    private function save(array $fixture): Preparation
    {
        return app(SavePreparationAction::class)->execute(
            $fixture['company'], $fixture['owner'], 'Masa', $fixture['output'], '8170', true,
            [
                ['inventory_item_id' => $fixture['flour']->id, 'quantity' => '5000'],
                ['inventory_item_id' => $fixture['water']->id, 'quantity' => '3000'],
            ],
        );
    }

    private function item(Company $company, Unit $unit, string $name): InventoryItem
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);

        return InventoryItem::factory()->for($company)->for($unit)->create([
            'ingredient_id' => $ingredient->id, 'name' => $name, 'is_active' => true,
        ]);
    }

    private function stock(array $fixture, InventoryItem $item, string $quantity, string $cost, ?string $expiresAt = null): void
    {
        app(ApplyInventoryMovementAction::class)->execute(
            $fixture['company'], $fixture['branch'], $item, InventoryMovementType::AdjustmentIn,
            $quantity, $cost, $fixture['owner'], reason: 'Stock de prueba',
            batch: ['expires_at' => $expiresAt ? CarbonImmutable::parse($expiresAt) : null],
        );
    }

    private function quantity(array $fixture, InventoryItem $item): string
    {
        return InventoryStock::query()->where('company_id', $fixture['company']->id)
            ->where('branch_id', $fixture['branch']->id)->where('inventory_item_id', $item->id)
            ->value('quantity') ?? '0.000';
    }

    private function reserve(array $fixture, InventoryItem $item, string $quantity): void
    {
        $product = Product::factory()->for($fixture['company'])->create();
        $variant = ProductVariant::factory()->for($fixture['company'])->for($product)->create();
        $order = Order::factory()->for($fixture['company'])->for($fixture['branch'])->create();
        $orderItem = OrderItem::factory()->for($fixture['company'])->for($order)->for($variant, 'productVariant')->create();
        InventoryReservation::query()->create([
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
            'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'inventory_item_id' => $item->id,
            'quantity' => $quantity, 'status' => InventoryReservationStatus::Reserved, 'reserved_at' => now(),
        ]);
    }

    private function user(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role, 'is_active' => true]);

        return $user;
    }

    private function asUser(User $user, array $fixture): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $fixture['company']->id,
            'active_branch_id' => $fixture['branch']->id,
        ]);
    }
}
