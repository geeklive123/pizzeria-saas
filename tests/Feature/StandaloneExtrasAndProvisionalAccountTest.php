<?php

namespace Tests\Feature;

use App\Actions\AddStandaloneExtraAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\OpenTableOrderAction;
use App\Actions\SaveToppingAction;
use App\Enums\CashSessionStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrinterPurpose;
use App\Enums\TableChargeMode;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PrintAttempt;
use App\Models\PrinterSetting;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use App\Printing\Renderers\CustomerTicketRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StandaloneExtrasAndProvisionalAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_extra_can_be_added_without_a_pizza_and_is_reserved_and_consumed_once(): void
    {
        $f = $this->fixture();

        $this->actingInContext($f)->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('Extras')
            ->assertSee('Extra independiente')
            ->assertSee('standalone_extra', false);

        $this->actingInContext($f)
            ->post(route('orders.items.store', $f['order']->ulid), [
                'standalone_extra' => $f['extra']->ulid,
                'quantity' => '2.000',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = $f['order']->items()->with('modifiers')->firstOrFail();
        $this->assertNull($item->product_variant_id);
        $this->assertSame('standalone_extra', $item->configuration_snapshot['type']);
        $this->assertSame('Tocino', $item->displayName());
        $this->assertSame('10.00', $item->line_total);
        $this->assertSame('20.000', $item->reservations()->value('quantity'));
        $this->assertSame(InventoryReservationStatus::Reserved, $item->reservations()->firstOrFail()->status);
        $this->assertSame('10.00', $f['order']->refresh()->extras_subtotal);
        $this->assertSame('0.00', $f['order']->other_subtotal);

        app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['waiter']);
        app(DispatchOrderToKitchenAction::class)->execute($f['order']->refresh(), $f['waiter']);

        $this->assertSame('80.000', $f['inventoryItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::query()
            ->where('inventory_item_id', $f['inventoryItem']->id)
            ->where('type', InventoryMovementType::OrderConsumption->value)
            ->count());
        $this->assertSame(InventoryReservationStatus::Consumed, $item->reservations()->firstOrFail()->refresh()->status);
    }

    public function test_provisional_account_can_be_printed_repeatedly_without_changing_order_or_inventory(): void
    {
        $f = $this->fixture();
        $item = app(AddStandaloneExtraAction::class)->execute($f['order'], $f['extra'], '2.000', $f['waiter']);
        app(DispatchOrderToKitchenAction::class)->execute($f['order'], $f['waiter']);
        $f['order']->forceFill(['status' => OrderStatus::ReadyForPayment])->save();
        $this->payment($f, '2.00');
        $this->printer($f);

        $this->actingInContext($f)->get(route('orders.show', $f['order']->ulid))
            ->assertOk()
            ->assertSee('IMPRIMIR CUENTA');

        $document = app(CustomerTicketRenderer::class)->renderProvisional($f['order']->refresh(), $f['waiter']);
        $this->assertStringContainsString('CUENTA PROVISIONAL', $document->plainText);
        $this->assertStringContainsString($f['table']->name, $document->plainText);
        $this->assertStringContainsString($f['order']->formattedOperationalNumber(), $document->plainText);
        $this->assertStringContainsString('2 TOCINO', $document->plainText);
        $this->assertStringContainsString('TOTAL', $document->plainText);
        $this->assertStringContainsString('QR', $document->plainText);
        $this->assertStringContainsString('PAGADO', $document->plainText);
        $this->assertStringContainsString('SALDO', $document->plainText);

        $before = $this->operationalState($f, $item);
        $this->actingInContext($f)->post(route('orders.account.print', $f['order']->ulid))->assertRedirect();
        $this->actingInContext($f)->post(route('orders.account.print', $f['order']->ulid))->assertRedirect();

        $this->assertSame(2, PrintAttempt::query()->where('order_id', $f['order']->id)->count());
        $this->assertCount(2, PrintAttempt::query()->where('order_id', $f['order']->id)->pluck('idempotency_key')->unique());
        $this->assertSame($before, $this->operationalState($f, $item));
        $this->assertSame(OrderStatus::ReadyForPayment, $f['order']->refresh()->status);
        $this->assertSame($f['table']->id, $f['order']->active_restaurant_table_id);
    }

    private function fixture(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $unit = Unit::factory()->for($company)->create(['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight]);
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => 'Tocino']);
        $inventoryItem = InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'name' => 'Tocino',
            'is_active' => true,
        ]);
        app(ApplyInventoryMovementAction::class)->execute(
            $company,
            $branch,
            $inventoryItem,
            InventoryMovementType::AdjustmentIn,
            '100.000',
            '1.000000',
            $waiter,
            reason: 'Stock de prueba',
        );
        $extra = app(SaveToppingAction::class)->execute($company, [
            'name' => 'Tocino',
            'description' => 'Porción extra',
            'price_delta' => '5.00',
            'inventory_item_ulid' => $inventoryItem->ulid,
            'default_quantity' => '10.000',
            'sort_order' => 0,
            'is_active' => true,
            'size_rules' => [],
        ]);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'Mesa 4']);
        $order = app(OpenTableOrderAction::class)->execute(
            $company,
            $branch,
            $table,
            $waiter,
            null,
            TableChargeMode::AtEnd,
        );

        return compact('company', 'branch', 'waiter', 'inventoryItem', 'extra', 'table', 'order');
    }

    private function payment(array $f, string $amount): void
    {
        $register = CashRegister::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'name' => 'Caja prueba',
            'is_active' => true,
        ]);
        $session = CashSession::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'cash_register_id' => $register->id,
            'active_cash_register_id' => $register->id,
            'opened_by' => $f['waiter']->id,
            'opening_amount' => '0.00',
            'expected_cash_amount' => '0.00',
            'status' => CashSessionStatus::Open,
            'opened_at' => now(),
        ]);
        Payment::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'order_id' => $f['order']->id,
            'cash_session_id' => $session->id,
            'method' => PaymentMethod::Qr,
            'amount' => $amount,
            'paid_at' => now(),
            'received_by' => $f['waiter']->id,
            'status' => PaymentStatus::Completed,
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    private function printer(array $f): void
    {
        PrinterSetting::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'purpose' => PrinterPurpose::CustomerTicket,
            'windows_printer_name' => 'Impresora prueba',
            'is_active' => true,
            'copies' => 1,
            'paper_width_mm' => 80,
        ]);
    }

    private function actingInContext(array $f): static
    {
        return $this->actingAs($f['waiter'])->withSession([
            'active_company_id' => $f['company']->id,
            'active_branch_id' => $f['branch']->id,
        ]);
    }

    private function operationalState(array $f, $item): array
    {
        return [
            'order_status' => $f['order']->refresh()->status->value,
            'active_table' => $f['order']->active_restaurant_table_id,
            'item_status' => $item->refresh()->status->value,
            'stock' => $f['inventoryItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity'),
            'reservation_status' => InventoryReservation::query()->where('order_item_id', $item->id)->value('status'),
            'movements' => InventoryMovement::query()->where('reference_id', $item->id)->count(),
            'dispatches' => $f['order']->kitchenDispatches()->count(),
            'payments' => $f['order']->payments()->count(),
        ];
    }
}
