<?php

namespace Tests\Feature;

use App\Actions\AddOrderItemAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenTableOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\MembershipRole;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SprintNineCTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_creates_a_hashed_user_membership_only_in_the_active_company(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherCompany = Company::factory()->create();

        $this->asUser($owner, $company, $branch)->post(route('memberships.store'), [
            'name' => 'Caja Uno',
            'email' => 'CAJA@PIZZERIA.TEST',
            'password' => 'secreto-seguro',
            'password_confirmation' => 'secreto-seguro',
            'role' => MembershipRole::Cashier->value,
            'is_active' => '1',
        ])->assertRedirect(route('memberships.index'));

        $user = User::query()->where('email', 'caja@pizzeria.test')->firstOrFail();
        $this->assertTrue(Hash::check('secreto-seguro', $user->password));
        $this->assertDatabaseHas('memberships', ['company_id' => $company->id, 'user_id' => $user->id, 'role' => MembershipRole::Cashier->value, 'is_active' => true]);
        $this->assertDatabaseMissing('memberships', ['company_id' => $otherCompany->id, 'user_id' => $user->id]);
    }

    public function test_existing_user_is_reused_without_crossing_or_replacing_other_company_access(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherCompany = Company::factory()->create();
        $existing = User::factory()->create(['email' => 'shared@pizzeria.test', 'password' => Hash::make('original-password')]);
        Membership::factory()->for($otherCompany)->for($existing)->create(['role' => MembershipRole::Kitchen]);

        $this->asUser($owner, $company, $branch)->post(route('memberships.store'), [
            'name' => 'Nombre enviado', 'email' => $existing->email,
            'password' => 'nuevo-secreto', 'password_confirmation' => 'nuevo-secreto',
            'role' => MembershipRole::Waiter->value, 'is_active' => '1',
        ])->assertRedirect(route('memberships.index'));

        $this->assertSame(1, User::query()->where('email', $existing->email)->count());
        $this->assertTrue(Hash::check('original-password', $existing->refresh()->password));
        $this->assertDatabaseHas('memberships', ['company_id' => $otherCompany->id, 'user_id' => $existing->id, 'role' => MembershipRole::Kitchen->value]);
        $this->assertDatabaseHas('memberships', ['company_id' => $company->id, 'user_id' => $existing->id, 'role' => MembershipRole::Waiter->value]);
    }

    public function test_waiter_and_kitchen_cannot_create_users(): void
    {
        [$company, $branch] = $this->context();
        $payload = ['name' => 'Sin permiso', 'email' => 'no@pizzeria.test', 'password' => 'secreto-seguro', 'password_confirmation' => 'secreto-seguro', 'role' => MembershipRole::Cashier->value, 'is_active' => '1'];

        foreach ([MembershipRole::Waiter, MembershipRole::Kitchen] as $role) {
            $user = $this->member($company, $role);
            $this->asUser($user, $company, $branch)->post(route('memberships.store'), $payload)->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'no@pizzeria.test']);
    }

    public function test_last_owner_is_protected_and_admin_can_update_password_without_deleting_history(): void
    {
        [$company, $branch, $owner, $ownerMembership] = $this->context();

        foreach ([[MembershipRole::Admin->value, '1'], [MembershipRole::Owner->value, null]] as [$role, $active]) {
            $response = $this->asUser($owner, $company, $branch)->put(route('memberships.update', $ownerMembership->id), [
                'name' => $owner->name, 'role' => $role, 'is_active' => $active,
            ]);
            $response->assertSessionHasErrors('membership');
            $this->assertSame(MembershipRole::Owner, $ownerMembership->refresh()->role);
            $this->assertTrue($ownerMembership->is_active);
        }

        $cashier = $this->member($company, MembershipRole::Cashier);
        $cashierMembership = $cashier->memberships()->where('company_id', $company->id)->firstOrFail();
        $order = Order::factory()->for($branch)->create(['company_id' => $company->id, 'created_by' => $cashier->id]);
        $admin = $this->member($company, MembershipRole::Admin);
        $this->asUser($admin, $company, $branch)->put(route('memberships.update', $cashierMembership->id), [
            'name' => 'Caja Actualizada', 'password' => 'clave-actualizada', 'password_confirmation' => 'clave-actualizada',
            'role' => MembershipRole::Cashier->value,
        ])->assertRedirect(route('memberships.index'));

        $this->assertFalse($cashierMembership->refresh()->is_active);
        $this->assertTrue(Hash::check('clave-actualizada', $cashier->refresh()->password));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'created_by' => $cashier->id]);
    }

    public function test_table_management_is_scoped_non_destructive_and_restricted_by_role(): void
    {
        [$company, $branch, $owner] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);

        $this->asUser($owner, $company, $branch)->post(route('tables.store'), ['name' => 'Mesa 20', 'capacity' => 6, 'sort_order' => 20, 'is_active' => '1'])->assertRedirect(route('tables.index'));
        $this->asUser($admin, $company, $branch)->post(route('tables.store'), ['name' => 'Mesa 21', 'capacity' => 4, 'sort_order' => 21, 'is_active' => '1'])->assertRedirect(route('tables.index'));
        $table = RestaurantTable::query()->where('name', 'Mesa 20')->firstOrFail();
        $this->assertSame($company->id, $table->company_id);
        $this->assertSame($branch->id, $table->branch_id);

        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $foreignTable = RestaurantTable::factory()->for($otherBranch)->create(['company_id' => $otherCompany->id]);
        $this->asUser($owner, $company, $branch)->put(route('tables.update', $foreignTable->ulid), ['name' => 'Inválida', 'capacity' => 2, 'sort_order' => 1, 'is_active' => '1'])->assertNotFound();

        $waiter = $this->member($company, MembershipRole::Waiter);
        $this->asUser($waiter, $company, $branch)->post(route('tables.store'), ['name' => 'Sin permiso', 'capacity' => 2, 'sort_order' => 1, 'is_active' => '1'])->assertForbidden();
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $table));
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id]);
    }

    public function test_inactive_tables_are_hidden_from_sale_and_empty_states_follow_permissions(): void
    {
        [$company, $branch, $owner] = $this->context();
        $inactive = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'Mesa inactiva', 'is_active' => false]);

        $this->asUser($owner, $company, $branch)->get(route('sales.create'))->assertOk()->assertSee('En mesa')->assertSee('Para llevar')->assertDontSee($inactive->name)->assertSee('Crear primera mesa');
        $waiter = $this->member($company, MembershipRole::Waiter);
        $this->asUser($waiter, $company, $branch)->get(route('sales.create'))->assertOk()->assertSee('Solicita a un administrador')->assertDontSee('Crear primera mesa');
        $this->asUser($owner, $company, $branch)->get(route('memberships.index'))->assertOk()->assertSee('+ Nuevo usuario');
    }

    public function test_free_table_opens_one_order_and_occupied_table_reuses_the_same_pos(): void
    {
        [$company, $branch, $owner] = $this->context();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);

        $first = $this->asUser($owner, $company, $branch)->post(route('tables.open', $table->ulid), ['charge_mode' => TableChargeMode::PerBatch->value]);
        $order = Order::query()->firstOrFail();
        $first->assertRedirect(route('orders.show', $order->ulid));
        $this->asUser($owner, $company, $branch)->post(route('tables.open', $table->ulid), ['charge_mode' => TableChargeMode::PerBatch->value])->assertRedirect(route('orders.show', $order->ulid));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($table->id, $order->active_restaurant_table_id);
        $this->assertSame(TableChargeMode::PerBatch, $order->charge_mode);
    }

    public function test_takeaway_and_later_table_additions_keep_pos_kitchen_inventory_and_checkout_flow(): void
    {
        [$company, $branch, $owner] = $this->context();
        $unit = Unit::factory()->for($company)->create(['type' => UnitType::Unit, 'symbol' => 'u']);
        $product = Product::factory()->for($company)->create(['type' => ProductType::Beverage]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['price' => '10.00', 'requires_preparation' => false]);
        $inventoryItem = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'product_variant_id' => $variant->id, 'name' => $product->name, 'is_active' => true]);
        app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventoryItem, InventoryMovementType::AdjustmentIn, '10.000', '1.000000', $owner, reason: 'Stock prueba');

        $this->asUser($owner, $company, $branch)->post(route('orders.takeaway.store'), ['customer_name' => 'Ana', 'customer_phone' => '70000000', 'notes' => 'Sin bolsa'])->assertRedirect();
        $takeaway = Order::query()->firstOrFail();
        $this->assertSame(OrderType::Takeaway, $takeaway->type);
        $this->assertSame('Ana', $takeaway->customer_name);
        $this->assertSame('70000000', $takeaway->customer_phone);
        $this->asUser($owner, $company, $branch)->get(route('orders.show', $takeaway->ulid))->assertOk()->assertSee('Para llevar');

        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = app(OpenTableOrderAction::class)->execute($company, $branch, $table, $owner);
        $first = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        $second = app(AddOrderItemAction::class)->execute($order, $variant, '1.000', $owner);
        app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame($order->id, $first->order_id);
        $this->assertSame($order->id, $second->order_id);
        $this->assertSame(2, $order->kitchenDispatches()->count());
        $this->assertSame(2, InventoryReservation::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::OrderConsumption->value)->count());
        $this->assertSame('8.000', InventoryStock::query()->where('inventory_item_id', $inventoryItem->id)->value('quantity'));
        $this->asUser($owner, $company, $branch)->get(route('orders.checkout', $order->ulid))->assertOk()->assertSee('Saldo');
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        $membership = Membership::factory()->for($company)->for($owner)->owner()->create();

        return [$company, $branch, $owner, $membership];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function asUser(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
