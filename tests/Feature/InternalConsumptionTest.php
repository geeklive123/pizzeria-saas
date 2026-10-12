<?php

namespace Tests\Feature;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\ApplyInventoryMovementAction;
use App\Actions\CancelInternalConsumptionAction;
use App\Actions\CreateInternalConsumptionAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\UpdateRecipeAction;
use App\Enums\InternalConsumptionReason;
use App\Enums\InventoryMovementType;
use App\Enums\MembershipRole;
use App\Enums\ModifierOptionType;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Enums\ProductModifierPurpose;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InternalConsumption;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\MembershipPermissionOverride;
use App\Models\ModifierOption;
use App\Models\ModifierOptionSizeRule;
use App\Models\PackagingRule;
use App\Models\PrintAgent;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductModifier;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Printing\Renderers\InternalConsumptionCommandRenderer;
use App\Services\InternalConsumptionReportService;
use App\Services\PrintAgentQueueService;
use App\Services\ThermalPrintingService;
use App\Support\UiFormatter;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalConsumptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_and_inventory_movements_share_the_same_instant_near_local_midnight(): void
    {
        $instant = CarbonImmutable::parse('2026-10-11 00:05:00', 'America/La_Paz')->utc();
        $this->travelTo($instant);
        $f = $this->fixture();

        $consumption = $this->create($f, [$this->pizza([$f['a']])])->fresh(['components.movement']);

        $this->assertTrue(
            $consumption->confirmed_at->equalTo($instant),
            "confirmed_at esperado {$instant->toIso8601String()}, recibido {$consumption->confirmed_at->toIso8601String()}",
        );
        $this->assertTrue(
            $consumption->created_at->equalTo($instant),
            "created_at esperado {$instant->toIso8601String()}, recibido {$consumption->created_at->toIso8601String()}",
        );
        $this->assertSame('11/10/2026 00:05', UiFormatter::date($consumption->confirmed_at, true));
        $consumption->forceFill(['notes' => 'Actualización posterior'])->save();
        $this->assertTrue($consumption->fresh()->confirmed_at->equalTo($instant));
        foreach ($consumption->components as $component) {
            $this->assertTrue($component->movement->occurred_at->equalTo($instant));
            $this->assertTrue($component->movement->created_at->equalTo($instant));
            $this->assertTrue($component->movement->occurred_at->equalTo($consumption->confirmed_at));
        }
    }

    public function test_all_zero_inventory_costs_are_reported_as_unknown_instead_of_partial_zero(): void
    {
        $f = $this->fixture(unitCost: '0.000000');

        $consumption = $this->create($f, [$this->pizza([$f['a']])]);

        $this->assertSame('unknown', $consumption->cost_status);
        $this->assertSame('0.000000', $consumption->total_cost);
        $this->assertTrue($consumption->components->every(fn ($component): bool => $component->cost_status === 'unknown'));
        $consumption->forceFill(['cost_status' => 'partial'])->save();
        $this->assertSame('unknown', $consumption->fresh('components')->effectiveCostStatus());
        $this->actingAs($f['owner'])->withSession(['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id])
            ->get(route('internal-consumptions.show', $consumption->ulid))
            ->assertOk()
            ->assertSee('Costo histórico:')
            ->assertSee('Desconocido')
            ->assertDontSee('Bs 0,00');
    }

    public function test_complete_and_fused_pizzas_consume_real_recipe_extras_removals_packaging_and_quantity(): void
    {
        $f = $this->fixture();
        $extra = $this->modifier($f, 'Extra queso', ModifierOptionType::Add, $f['cheeseItem'], '50.000');
        $remove = $this->modifier($f, 'Sin A', ModifierOptionType::Remove, $f['aItem']);
        PackagingRule::query()->create(['company_id' => $f['company']->id, 'size_key' => 'familiar', 'fulfillment_type' => OrderType::Takeaway, 'inventory_item_id' => $f['boxItem']->id, 'quantity' => '1.000']);

        $consumption = $this->create($f, [
            $this->pizza([$f['a']], '2', OrderType::Takeaway, [['option' => $extra->ulid]], []),
            $this->pizza([$f['a'], $f['b']], '1', OrderType::DineIn, [['option' => $remove->ulid]], []),
        ]);

        $this->assertCount(2, $consumption->items);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'internal_consumption', 'inventory_item_id' => $f['baseItem']->id, 'quantity' => 900]);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'internal_consumption', 'inventory_item_id' => $f['boxItem']->id, 'quantity' => 2]);
        $this->assertSame('familiar', $consumption->items->first()->configuration_snapshot['size_key']);
        $this->assertSame('known', $consumption->cost_status);
        $this->assertSame('5304.000000', $consumption->total_cost);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('print_attempts', 0);
        $session = ['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id];
        $this->actingAs($f['owner'])->withSession($session)->get(route('internal-consumptions.index'))->assertOk()->assertSee($consumption->code);
        $this->actingAs($f['owner'])->withSession($session)->get(route('internal-consumptions.show', $consumption->ulid))->assertOk()->assertSee('Ingredientes y movimientos');
    }

    public function test_historical_snapshot_and_cost_survive_catalog_and_recipe_changes(): void
    {
        $f = $this->fixture();
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        $cost = $consumption->total_cost;
        $snapshot = $consumption->items->first()->configuration_snapshot;

        $f['a']->product->update(['name' => 'Nombre nuevo']);
        app(UpdateRecipeAction::class)->execute($f['company'], $f['a'], [
            ['ingredient_id' => $f['base']->id, 'component_type' => 'base', 'quantity' => '100.000'],
            ['ingredient_id' => $f['cheese']->id, 'component_type' => 'base', 'quantity' => '10.000'],
        ], 'Receta cambiada');

        $this->assertSame($cost, $consumption->fresh()->total_cost);
        $this->assertSame($snapshot, $consumption->items->first()->fresh()->configuration_snapshot);
        $this->assertSame('Sabor A', $snapshot['sections'][0]['product']);
    }

    public function test_topping_catalog_uses_size_quantity_and_is_snapshotted(): void
    {
        $f = $this->fixture();
        $modifier = ProductModifier::query()->create([
            'company_id' => $f['company']->id,
            'purpose' => ProductModifierPurpose::ToppingCatalog,
            'name' => 'Toppings',
            'is_active' => true,
        ]);
        $topping = ModifierOption::query()->create([
            'company_id' => $f['company']->id,
            'product_modifier_id' => $modifier->id,
            'name' => 'Queso especial',
            'type' => ModifierOptionType::Add,
            'price_delta' => '5.00',
            'inventory_item_id' => $f['cheeseItem']->id,
            'unit_id' => $f['cheeseItem']->unit_id,
            'is_active' => true,
        ]);
        ModifierOptionSizeRule::query()->create([
            'company_id' => $f['company']->id,
            'modifier_option_id' => $topping->id,
            'size_key' => 'familiar',
            'quantity' => '75.000',
            'price_delta' => '8.00',
        ]);

        $consumption = $this->create($f, [$this->pizza([$f['a']], toppings: [$topping->ulid])]);
        $item = $consumption->items->first();

        $this->assertDatabaseHas('internal_consumption_components', [
            'internal_consumption_item_id' => $item->id,
            'inventory_item_id' => $f['cheeseItem']->id,
            'quantity' => 375,
        ]);
        $this->assertSame('Queso especial', $item->configuration_snapshot['modifiers'][0]['name']);
        $this->assertSame('75.000', $item->configuration_snapshot['modifiers'][0]['quantity']);
    }

    public function test_separate_monthly_report_groups_only_confirmed_internal_costs(): void
    {
        $f = $this->fixture();
        $marketing = $this->create($f, [$this->pizza([$f['a']])]);
        app(CreateInternalConsumptionAction::class)->execute(
            $f['company'], $f['branch'], $f['owner'], InternalConsumptionReason::Tasting,
            null, (string) Str::ulid(), [$this->pizza([$f['b']], '2')],
        );
        app(CancelInternalConsumptionAction::class)->execute($marketing, $f['owner'], 'No se utilizó', true);

        $report = collect(app(InternalConsumptionReportService::class)->monthly($f['company'], $f['branch']));

        $this->assertSame('0.000', $report->firstWhere('reason', InternalConsumptionReason::Marketing)['pizzas']);
        $this->assertSame('2.000', $report->firstWhere('reason', InternalConsumptionReason::Tasting)['pizzas']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_insufficient_stock_rolls_back_everything(): void
    {
        $f = $this->fixture('100.000');
        try {
            $this->create($f, [$this->pizza([$f['a']])]);
            $this->fail('El consumo debió rechazarse.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseCount('internal_consumptions', 0);
            $this->assertDatabaseMissing('inventory_movements', ['type' => 'internal_consumption']);
        }
    }

    public function test_same_idempotency_key_never_consumes_twice(): void
    {
        $f = $this->fixture();
        $key = (string) Str::ulid();
        $first = $this->create($f, [$this->pizza([$f['a']])], $key);
        $second = $this->create($f, [$this->pizza([$f['a']])], $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InternalConsumption::query()->count());
        $this->assertSame(3, InventoryMovement::query()->where('type', InventoryMovementType::InternalConsumption)->count());
    }

    public function test_commercial_reservations_reduce_availability_for_internal_consumption(): void
    {
        $f = $this->fixture('800.000');
        $order = app(CreateTakeawayOrderAction::class)->execute($f['company'], $f['branch'], $f['owner']);
        app(AddConfiguredPizzaAction::class)->execute(
            $order,
            [['variant' => $f['a']->ulid]],
            '1.000',
            $f['owner'],
            OrderType::DineIn,
        );
        $physicalBefore = $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity');

        try {
            $this->create($f, [$this->pizza([$f['a']])]);
            $this->fail('El consumo interno no debe utilizar stock reservado para un pedido.');
        } catch (InsufficientStockException) {
            $this->assertSame($physicalBefore, $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity'));
            $this->assertDatabaseMissing('inventory_movements', ['type' => InventoryMovementType::InternalConsumption->value]);
            $this->assertDatabaseCount('internal_consumptions', 0);
        }
    }

    public function test_cancellation_creates_reversals_and_duplicate_is_rejected(): void
    {
        $f = $this->fixture();
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        $before = $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity');
        $cancelledAt = CarbonImmutable::parse('2026-10-11 00:10:00', 'America/La_Paz')->utc();
        $this->travelTo($cancelledAt);

        app(CancelInternalConsumptionAction::class)->execute($consumption, $f['owner'], 'Grabación cancelada', true);

        $this->assertSame('cancelled', $consumption->fresh()->status->value);
        $cancellation = $consumption->fresh('cancellation')->cancellation;
        $this->assertTrue($cancellation->cancelled_at->equalTo($cancelledAt));
        $cancellation->forceFill(['reason' => 'Corrección de texto'])->save();
        $this->assertTrue($cancellation->fresh()->cancelled_at->equalTo($cancelledAt));
        $this->assertSame('5000.000', $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity'));
        $this->assertNotSame($before, '5000.000');
        $this->assertSame(3, InventoryMovement::query()->where('type', InventoryMovementType::Reversal)->count());
        $this->expectException(DomainException::class);
        app(CancelInternalConsumptionAction::class)->execute($consumption->fresh(), $f['owner'], 'Segundo intento', true);
    }

    public function test_cancellation_requires_backend_declaration_and_accepts_a_valid_confirmation(): void
    {
        $f = $this->fixture();
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        $session = ['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id];

        $this->actingAs($f['owner'])->withSession($session)
            ->from(route('internal-consumptions.show', $consumption->ulid))
            ->post(route('internal-consumptions.cancel', $consumption->ulid), [
                'reason' => 'No se preparará',
            ])
            ->assertRedirect(route('internal-consumptions.show', $consumption->ulid))
            ->assertSessionHasErrors('confirm_not_prepared');

        $this->assertSame('confirmed', $consumption->fresh()->status->value);
        $this->assertDatabaseCount('internal_consumption_cancellations', 0);
        $this->assertDatabaseMissing('inventory_movements', ['type' => InventoryMovementType::Reversal->value]);

        $this->actingAs($f['owner'])->withSession($session)
            ->post(route('internal-consumptions.cancel', $consumption->ulid), [
                'reason' => 'No se preparará',
                'confirm_not_prepared' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $consumption->fresh()->status->value);
        $this->assertSame('5000.000', $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity'));
    }

    public function test_cancellation_action_rejects_a_false_declaration_without_side_effects(): void
    {
        $f = $this->fixture();
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);

        try {
            app(CancelInternalConsumptionAction::class)->execute($consumption, $f['owner'], 'No fue preparada', false);
            $this->fail('La Action debió exigir la declaración de no preparación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Debes confirmar', $exception->getMessage());
        }

        $this->assertSame('confirmed', $consumption->fresh()->status->value);
        $this->assertDatabaseCount('internal_consumption_cancellations', 0);
        $this->assertDatabaseMissing('inventory_movements', ['type' => InventoryMovementType::Reversal->value]);
    }

    public function test_cancellation_invalidates_pending_retryable_and_claimed_internal_prints_but_preserves_printed_history(): void
    {
        $f = $this->fixture();
        $setting = PrinterSetting::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'purpose' => PrinterPurpose::Kitchen,
            'windows_printer_name' => 'Cocina',
            'is_active' => true,
            'auto_print' => false,
            'copies' => 1,
            'paper_width_mm' => 80,
        ]);
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        $printing = app(ThermalPrintingService::class);
        $pending = $printing->internalConsumption($consumption, $f['owner'], 'pending');
        $retryable = $printing->internalConsumption($consumption, $f['owner'], 'retryable');
        $retryable->update([
            'status' => PrintAttemptStatus::Failed,
            'available_at' => now(),
            'error_message' => 'Sin papel',
        ]);
        $claimed = $printing->internalConsumption($consumption, $f['owner'], 'claimed');
        $claimed->update([
            'status' => PrintAttemptStatus::Claimed,
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes(2),
            'claim_token_hash' => hash('sha256', 'claim'),
        ]);
        $printed = $printing->internalConsumption($consumption, $f['owner'], 'printed');
        $printed->update([
            'status' => PrintAttemptStatus::Printed,
            'available_at' => null,
            'printed_at' => now(),
        ]);
        $agent = PrintAgent::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'name' => 'Agente cocina',
            'token_hash' => hash('sha256', 'test-agent-token'),
            'is_active' => true,
        ]);

        app(CancelInternalConsumptionAction::class)->execute($consumption, $f['owner'], 'No fue preparada', true);

        $this->assertSame(PrintAttemptStatus::Failed, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->available_at);
        $this->assertStringContainsString('Invalidada', (string) $pending->fresh()->error_message);
        $this->assertSame(PrintAttemptStatus::Failed, $retryable->fresh()->status);
        $this->assertNull($retryable->fresh()->available_at);
        $this->assertSame(PrintAttemptStatus::Failed, $claimed->fresh()->status);
        $this->assertNull($claimed->fresh()->available_at);
        $this->assertNotNull($claimed->fresh()->claim_token_hash);
        $this->assertSame(PrintAttemptStatus::Printed, $printed->fresh()->status);
        $this->assertNotNull($printed->fresh()->printed_at);
        $this->assertDatabaseCount('print_attempts', 4);
        $this->assertNull(app(PrintAgentQueueService::class)->claim($agent));
        $this->assertSame($setting->id, $pending->fresh()->printer_setting_id);
    }

    public function test_cancelled_claimed_command_cannot_be_reclaimed_after_its_claim_expires(): void
    {
        $f = $this->fixture();
        PrinterSetting::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'purpose' => PrinterPurpose::Kitchen,
            'windows_printer_name' => 'Cocina',
            'is_active' => true,
            'auto_print' => false,
            'copies' => 1,
            'paper_width_mm' => 80,
        ]);
        $agent = PrintAgent::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'name' => 'Agente cocina',
            'token_hash' => hash('sha256', 'test-agent-token'),
            'is_active' => true,
        ]);
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        app(ThermalPrintingService::class)->internalConsumption($consumption, $f['owner'], 'claimed-before-cancel');
        $queue = app(PrintAgentQueueService::class);
        $claim = $queue->claim($agent);

        $this->assertNotNull($claim);
        $this->assertSame(PrintAttemptStatus::Claimed, $claim['job']->status);

        app(CancelInternalConsumptionAction::class)->execute($consumption, $f['owner'], 'No fue preparada', true);
        $invalidated = $claim['job']->fresh();

        $this->assertSame(PrintAttemptStatus::Failed, $invalidated->status);
        $this->assertNull($invalidated->available_at);
        $this->assertNotNull($invalidated->claim_expires_at);
        $this->assertNotNull($invalidated->claim_token_hash);

        $this->travel(3)->minutes();

        $this->assertNull($queue->claim($agent));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya no pertenece a este claim');
        $queue->printed($agent, $invalidated->ulid, $claim['claim_token']);
    }

    public function test_printing_is_manual_idempotent_and_marks_reprints(): void
    {
        $f = $this->fixture();
        PrinterSetting::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'purpose' => PrinterPurpose::Kitchen, 'windows_printer_name' => 'Cocina', 'is_active' => true, 'auto_print' => true, 'copies' => 1, 'paper_width_mm' => 80]);
        $consumption = $this->create($f, [$this->pizza([$f['a']], notes: 'Sin cortar')]);
        $this->assertDatabaseCount('print_attempts', 0);

        $service = app(ThermalPrintingService::class);
        $first = $service->internalConsumption($consumption, $f['owner'], 'internal:original');
        $same = $service->internalConsumption($consumption, $f['owner'], 'internal:original');
        $reprint = $service->internalConsumption($consumption, $f['owner'], 'internal:reprint:1');
        $text = app(InternalConsumptionCommandRenderer::class)->render($consumption, false)->plainText;
        $stockBeforeFailure = $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity');
        $movementCountBeforeFailure = InventoryMovement::query()->count();
        $agent = PrintAgent::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'name' => 'Agente cocina',
            'token_hash' => hash('sha256', 'test-agent-token'),
            'is_active' => true,
        ]);
        $claim = app(PrintAgentQueueService::class)->claim($agent);
        $failed = app(PrintAgentQueueService::class)->failed(
            $agent,
            $claim['job']->ulid,
            $claim['claim_token'],
            'Impresora sin papel',
        );

        $this->assertSame($first->id, $same->id);
        $this->assertFalse($first->is_reprint);
        $this->assertTrue($reprint->is_reprint);
        $this->assertSame($consumption->id, $claim['job']->internal_consumption_id);
        $this->assertSame('failed', $failed->status->value);
        $this->assertSame($stockBeforeFailure, $f['baseItem']->inventoryStocks()->where('branch_id', $f['branch']->id)->value('quantity'));
        $this->assertSame($movementCountBeforeFailure, InventoryMovement::query()->count());
        $this->assertDatabaseCount('print_attempts', 2);
        $this->assertStringContainsString('CONSUMO INTERNO', $text);
        $this->assertStringContainsString('Publicidad / Marketing', $text);
        $this->assertStringNotContainsString('Bs ', $text);
    }

    public function test_print_idempotency_is_scoped_to_consumption_and_company(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        foreach ([$first, $second] as $fixture) {
            PrinterSetting::query()->create([
                'company_id' => $fixture['company']->id,
                'branch_id' => $fixture['branch']->id,
                'purpose' => PrinterPurpose::Kitchen,
                'windows_printer_name' => 'Cocina',
                'is_active' => true,
                'auto_print' => false,
                'copies' => 1,
                'paper_width_mm' => 80,
            ]);
        }
        $firstConsumption = $this->create($first, [$this->pizza([$first['a']])]);
        $secondConsumption = $this->create($second, [$this->pizza([$second['a']])]);

        $firstAttempt = app(ThermalPrintingService::class)->internalConsumption($firstConsumption, $first['owner'], 'same-client-key');
        $secondAttempt = app(ThermalPrintingService::class)->internalConsumption($secondConsumption, $second['owner'], 'same-client-key');

        $this->assertNotSame($firstAttempt->id, $secondAttempt->id);
        $this->assertNotSame($firstAttempt->idempotency_key, $secondAttempt->idempotency_key);
        $this->assertSame($firstConsumption->id, $firstAttempt->internal_consumption_id);
        $this->assertSame($secondConsumption->id, $secondAttempt->internal_consumption_id);
    }

    public function test_cancelled_consumption_cannot_be_printed(): void
    {
        $f = $this->fixture();
        PrinterSetting::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'purpose' => PrinterPurpose::Kitchen,
            'windows_printer_name' => 'Cocina',
            'is_active' => true,
            'auto_print' => false,
            'copies' => 1,
            'paper_width_mm' => 80,
        ]);
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        app(CancelInternalConsumptionAction::class)->execute($consumption, $f['owner'], 'Ya no se preparará', true);

        $this->assertFalse($f['owner']->can('print', $consumption->fresh()));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('anulado no puede enviarse a cocina');
        app(ThermalPrintingService::class)->internalConsumption($consumption->fresh(), $f['owner'], 'cancelled');
    }

    public function test_http_permissions_and_multi_company_ids_are_enforced(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $waiter = User::factory()->create();
        Membership::factory()->for($f['company'])->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $session = ['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id];

        $this->actingAs($waiter)->withSession($session)->get(route('internal-consumptions.create'))->assertOk()->assertSee('Nuevo consumo interno');
        $this->actingAs($waiter)->withSession($session)->post(route('internal-consumptions.preview'), [
            'reason' => 'marketing', 'idempotency_key' => (string) Str::ulid(),
            'items' => [$this->pizza([$other['a']])],
        ])->assertSessionHasErrors('items.0.sections.0.variant');

        $this->actingAs($waiter)->withSession($session)->post(route('internal-consumptions.preview'), [
            'reason' => 'marketing', 'idempotency_key' => (string) Str::ulid(),
            'items' => [$this->pizza([$f['a']]), $this->pizza([$f['a']])],
        ])->assertOk()->assertSee('Confirmar consumo interno');
    }

    public function test_routes_enforce_role_permissions_and_cross_company_scope(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $otherConsumption = $this->create($other, [$this->pizza([$other['a']])]);
        $session = ['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id];

        $this->actingAs($f['owner'])->withSession($session)
            ->get(route('internal-consumptions.show', $otherConsumption->ulid))->assertNotFound();
        $this->actingAs($f['owner'])->withSession($session)
            ->post(route('internal-consumptions.print', $otherConsumption->ulid), ['idempotency_key' => 'cross-company'])->assertNotFound();
        $this->actingAs($f['owner'])->withSession($session)
            ->post(route('internal-consumptions.cancel', $otherConsumption->ulid), [
                'reason' => 'Intento cruzado',
                'confirm_not_prepared' => '1',
            ])->assertNotFound();
        $this->actingAs($f['owner'])->withSession($session)
            ->from(route('internal-consumptions.index'))
            ->get(route('internal-consumptions.index', ['month' => 'not-a-month']))
            ->assertRedirect(route('internal-consumptions.index'))->assertSessionHasErrors('month');

        $kitchen = User::factory()->create();
        Membership::factory()->for($f['company'])->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingAs($kitchen)->withSession($session)
            ->get(route('internal-consumptions.create'))->assertForbidden();

        $waiter = User::factory()->create();
        Membership::factory()->for($f['company'])->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $this->actingAs($waiter)->withSession($session)
            ->get(route('internal-consumptions.index'))->assertOk()->assertDontSee('Resumen mensual');
    }

    public function test_cashier_admin_kitchen_and_denied_user_follow_effective_permissions(): void
    {
        $f = $this->fixture();
        PrinterSetting::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'purpose' => PrinterPurpose::Kitchen,
            'windows_printer_name' => 'Cocina',
            'is_active' => true,
            'auto_print' => false,
            'copies' => 1,
            'paper_width_mm' => 80,
        ]);
        $consumption = $this->create($f, [$this->pizza([$f['a']])]);
        $session = ['active_company_id' => $f['company']->id, 'active_branch_id' => $f['branch']->id];

        $cashier = User::factory()->create();
        Membership::factory()->for($f['company'])->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $cashierConsumption = app(CreateInternalConsumptionAction::class)->execute(
            $f['company'],
            $f['branch'],
            $cashier,
            InternalConsumptionReason::Staff,
            null,
            (string) Str::ulid(),
            [$this->pizza([$f['a']])],
        );
        $this->assertSame($cashier->id, $cashierConsumption->created_by);
        $this->actingAs($cashier)->withSession($session)
            ->get(route('internal-consumptions.create'))->assertOk();
        $this->actingAs($cashier)->withSession($session)
            ->post(route('internal-consumptions.print', $cashierConsumption->ulid), ['idempotency_key' => 'cashier-print'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($cashier)->withSession($session)
            ->post(route('internal-consumptions.cancel', $consumption->ulid), [
                'reason' => 'Intento de cajera',
                'confirm_not_prepared' => '1',
            ])->assertForbidden();

        $admin = User::factory()->create();
        Membership::factory()->for($f['company'])->for($admin)->create(['role' => MembershipRole::Admin]);
        $adminConsumption = app(CreateInternalConsumptionAction::class)->execute(
            $f['company'],
            $f['branch'],
            $admin,
            InternalConsumptionReason::Tasting,
            null,
            (string) Str::ulid(),
            [$this->pizza([$f['a']])],
        );
        $this->assertSame($admin->id, $adminConsumption->created_by);
        $this->actingAs($admin)->withSession($session)
            ->get(route('internal-consumptions.index'))->assertOk()->assertSee('Resumen mensual');
        $this->actingAs($admin)->withSession($session)
            ->get(route('internal-consumptions.create'))->assertOk();
        $this->actingAs($admin)->withSession($session)
            ->post(route('internal-consumptions.print', $adminConsumption->ulid), ['idempotency_key' => 'admin-print'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $kitchen = User::factory()->create();
        Membership::factory()->for($f['company'])->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingAs($kitchen)->withSession($session)
            ->get(route('internal-consumptions.show', $consumption->ulid))->assertOk();
        $this->actingAs($kitchen)->withSession($session)
            ->post(route('internal-consumptions.print', $consumption->ulid), ['idempotency_key' => 'kitchen-print'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($kitchen)->withSession($session)
            ->get(route('internal-consumptions.create'))->assertForbidden();

        $this->actingAs($admin)->withSession($session)
            ->post(route('internal-consumptions.cancel', $adminConsumption->ulid), [
                'reason' => 'No fue preparada',
                'confirm_not_prepared' => '1',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $adminConsumption->fresh()->status->value);

        $denied = User::factory()->create();
        $deniedMembership = Membership::factory()->for($f['company'])->for($denied)->create(['role' => MembershipRole::Waiter]);
        foreach ([
            Permission::ViewInternalConsumptions,
            Permission::CreateInternalConsumptions,
            Permission::PrintInternalConsumptions,
            Permission::CancelInternalConsumptions,
            Permission::ViewInternalConsumptionReports,
        ] as $permission) {
            MembershipPermissionOverride::query()->create([
                'company_id' => $f['company']->id,
                'membership_id' => $deniedMembership->id,
                'permission' => $permission,
                'allowed' => false,
            ]);
        }

        $this->actingAs($denied)->withSession($session)
            ->get(route('internal-consumptions.index'))->assertForbidden();
        $this->actingAs($denied)->withSession($session)
            ->get(route('internal-consumptions.create'))->assertForbidden();
        $this->actingAs($denied)->withSession($session)
            ->get(route('internal-consumptions.show', $consumption->ulid))->assertForbidden();
        $this->actingAs($denied)->withSession($session)
            ->post(route('internal-consumptions.print', $consumption->ulid), ['idempotency_key' => 'denied-print'])
            ->assertForbidden();
        $this->actingAs($denied)->withSession($session)
            ->post(route('internal-consumptions.cancel', $consumption->ulid), [
                'reason' => 'Intento denegado',
                'confirm_not_prepared' => '1',
            ])->assertForbidden();

        $otherBranch = Branch::factory()->for($f['company'])->create();
        $this->actingAs($f['owner'])->withSession([
            'active_company_id' => $f['company']->id,
            'active_branch_id' => $otherBranch->id,
        ])->get(route('internal-consumptions.show', $consumption->ulid))->assertNotFound();
    }

    public function test_invalid_variant_and_recipe_pending_are_rejected(): void
    {
        $f = $this->fixture();
        $inactive = ProductVariant::factory()->for($f['company'])->for(Product::factory()->for($f['company'])->create(['type' => ProductType::Pizza]))->create(['is_active' => false]);
        $this->expectException(DomainException::class);
        $this->create($f, [$this->pizza([$inactive])]);
    }

    private function create(array $f, array $items, ?string $key = null): InternalConsumption
    {
        return app(CreateInternalConsumptionAction::class)->execute($f['company'], $f['branch'], $f['owner'], InternalConsumptionReason::Marketing, 'Campaña octubre', $key ?? (string) Str::ulid(), $items);
    }

    private function pizza(array $variants, string $quantity = '1', OrderType $fulfillment = OrderType::DineIn, array $modifiers = [], array $toppings = [], ?string $notes = null): array
    {
        return ['quantity' => $quantity, 'fulfillment_type' => $fulfillment->value, 'sections' => array_map(fn (ProductVariant $variant): array => ['variant' => $variant->ulid], $variants), 'modifiers' => $modifiers, 'toppings' => $toppings, 'notes' => $notes];
    }

    private function fixture(string $stock = '5000.000', string $unitCost = '2.000000'): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);
        $grams = Unit::factory()->for($company)->create(['name' => 'Gramo', 'symbol' => 'g', 'type' => UnitType::Weight]);
        $units = Unit::factory()->for($company)->create(['name' => 'Unidad', 'symbol' => 'u', 'type' => UnitType::Unit]);
        [$base, $baseItem] = $this->ingredient($company, $grams, 'Masa');
        [$cheese, $cheeseItem] = $this->ingredient($company, $grams, 'Queso');
        [$aIngredient, $aItem] = $this->ingredient($company, $grams, 'Topping A');
        [$bIngredient, $bItem] = $this->ingredient($company, $grams, 'Topping B');
        [, $boxItem] = $this->ingredient($company, $units, 'Caja familiar');
        foreach ([$baseItem, $cheeseItem, $aItem, $bItem, $boxItem] as $item) {
            app(ApplyInventoryMovementAction::class)->execute($company, $branch, $item, InventoryMovementType::AdjustmentIn, $stock, $unitCost, $owner, reason: 'Stock test');
        }
        $a = $this->flavor($company, 'Sabor A', $base, $cheese, $aIngredient);
        $b = $this->flavor($company, 'Sabor B', $base, $cheese, $bIngredient);

        return compact('company', 'branch', 'owner', 'grams', 'units', 'base', 'cheese', 'a', 'b', 'baseItem', 'cheeseItem', 'aItem', 'bItem', 'boxItem');
    }

    private function ingredient(Company $company, Unit $unit, string $name): array
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);
        $item = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'ingredient_id' => $ingredient->id, 'name' => $name, 'is_active' => true]);

        return [$ingredient, $item];
    }

    private function flavor(Company $company, string $name, Ingredient $base, Ingredient $cheese, Ingredient $topping): ProductVariant
    {
        $product = Product::factory()->for($company)->create(['name' => $name, 'type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['name' => 'Familiar', 'size_key' => 'familiar', 'price' => '80.00']);
        app(UpdateRecipeAction::class)->execute($company, $variant, [
            ['ingredient_id' => $base->id, 'component_type' => 'base', 'quantity' => '450.000'],
            ['ingredient_id' => $cheese->id, 'component_type' => 'base', 'quantity' => '300.000'],
            ['ingredient_id' => $topping->id, 'component_type' => 'topping', 'quantity' => '120.000'],
        ], 'Receta '.$name);

        return $variant;
    }

    private function modifier(array $f, string $name, ModifierOptionType $type, InventoryItem $item, ?string $quantity = null): ModifierOption
    {
        $modifier = ProductModifier::query()->create(['company_id' => $f['company']->id, 'name' => $name, 'is_active' => true]);

        return ModifierOption::query()->create(['company_id' => $f['company']->id, 'product_modifier_id' => $modifier->id, 'name' => $name, 'type' => $type, 'price_delta' => '0.00', 'inventory_item_id' => $item->id, 'ingredient_id' => $item->ingredient_id, 'quantity' => $quantity, 'unit_id' => $item->unit_id, 'is_active' => true]);
    }
}
