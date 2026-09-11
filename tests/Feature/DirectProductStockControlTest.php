<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\ConsumeInventoryReservationAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectProductStockControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_untracked_direct_product_can_be_sold_without_reservations_or_inventory_movements(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();

        $this->actingInContext($owner, $company, $branch)
            ->post(route('products.store'), $this->productPayload('Servicio de cortesía', false))
            ->assertSessionHasNoErrors();

        $variant = ProductVariant::query()->whereHas(
            'product',
            fn ($query) => $query->where('name', 'Servicio de cortesía'),
        )->firstOrFail();
        $this->assertFalse($variant->requires_preparation);
        $this->assertNull($variant->inventoryItem);

        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $item = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);

        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('Servicio de cortesía')
            ->assertSee('Sin control de stock');

        app(ConsumeInventoryReservationAction::class)->execute($item, $owner);

        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_tracked_direct_product_creates_inventory_item_appears_in_inventory_and_reserves_stock(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();

        $this->actingInContext($owner, $company, $branch)
            ->post(route('products.store'), $this->productPayload('CERVEZA CORONA', true, $unit))
            ->assertSessionHasNoErrors();

        $variant = ProductVariant::query()->whereHas(
            'product',
            fn ($query) => $query->where('name', 'CERVEZA CORONA'),
        )->firstOrFail();
        $inventoryItem = $variant->inventoryItem()->firstOrFail();

        $this->assertSame($company->id, $inventoryItem->company_id);
        $this->assertSame($unit->id, $inventoryItem->unit_id);
        $this->actingInContext($owner, $company, $branch)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('CERVEZA CORONA')
            ->assertSee('Venta directa');

        $this->stock($company, $branch, $inventoryItem, $owner, '10.000');
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $orderItem = app(AddOrderItemAction::class)->execute($order, $variant, '2.000', $owner);

        $reservation = InventoryReservation::query()->sole();
        $this->assertSame($orderItem->id, $reservation->order_item_id);
        $this->assertSame($inventoryItem->id, $reservation->inventory_item_id);
        $this->assertSame('2.000', $reservation->quantity);
    }

    public function test_editing_a_tracked_direct_product_does_not_duplicate_its_inventory_item(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $this->actingInContext($owner, $company, $branch)
            ->post(route('products.store'), $this->productPayload('Agua mineral', true, $unit))
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('name', 'Agua mineral')->firstOrFail();
        $variant = $product->variants()->firstOrFail();
        $inventoryItemId = $variant->inventoryItem()->firstOrFail()->id;
        $payload = $this->productPayload('Agua mineral fría', true, $unit);
        $payload['variants'][0]['ulid'] = $variant->ulid;
        $payload['variants'][0]['price'] = '12.00';

        $this->actingInContext($owner, $company, $branch)
            ->put(route('products.update', $product->ulid), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, InventoryItem::query()->where('product_variant_id', $variant->id)->count());
        $this->assertSame($inventoryItemId, $variant->inventoryItem()->firstOrFail()->id);
        $this->assertSame('Agua mineral fría · Botella', $variant->inventoryItem()->firstOrFail()->name);
    }

    public function test_existing_direct_product_with_inventory_item_keeps_reserving_stock(): void
    {
        [$company, $branch, $owner, $unit] = $this->context();
        $product = Product::factory()->for($company)->create([
            'name' => 'Producto directo existente',
            'type' => ProductType::Beverage,
        ]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create([
            'name' => 'Unidad',
            'requires_preparation' => false,
        ]);
        $inventoryItem = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'product_variant_id' => $variant->id,
            'name' => $product->name,
            'is_active' => true,
        ]);
        $this->stock($company, $branch, $inventoryItem, $owner, '5.000');

        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => '1.000',
        ]);
    }

    /** @return array{Company, Branch, User, Unit} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $unit = Unit::factory()->for($company)->create([
            'name' => 'Unidad',
            'symbol' => 'u',
            'type' => UnitType::Unit,
        ]);

        return [$company, $branch, $owner, $unit];
    }

    /** @return array<string, mixed> */
    private function productPayload(string $name, bool $trackStock, ?Unit $unit = null): array
    {
        return [
            'name' => $name,
            'type' => ProductType::Beverage->value,
            'is_active' => '1',
            'variants' => [[
                'name' => 'Botella',
                'price' => '10.00',
                'track_stock' => $trackStock ? '1' : null,
                'inventory_unit_id' => $unit?->id,
                'is_active' => '1',
                'sort_order' => '0',
            ]],
        ];
    }

    private function stock(
        Company $company,
        Branch $branch,
        InventoryItem $inventoryItem,
        User $owner,
        string $quantity,
    ): InventoryMovement {
        return app(ApplyInventoryMovementAction::class)->execute(
            $company,
            $branch,
            $inventoryItem,
            InventoryMovementType::AdjustmentIn,
            $quantity,
            '1.000000',
            $owner,
            reason: 'Stock de prueba',
        );
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
