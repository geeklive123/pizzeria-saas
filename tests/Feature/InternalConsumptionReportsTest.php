<?php

namespace Tests\Feature;

use App\Enums\InternalConsumptionReason;
use App\Enums\InternalConsumptionStatus;
use App\Enums\InventoryMovementType;
use App\Enums\MembershipRole;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InternalConsumption;
use App\Models\InternalConsumptionComponent;
use App\Models\InternalConsumptionItem;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\InternalConsumptionReportService;
use App\Services\ProfitabilityReportService;
use App\Services\ReportDateRangeService;
use App\Services\ReportsOverviewService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalConsumptionReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_inventory_and_profitability_keep_internal_consumptions_separate_and_subtract_known_cost_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'America/La_Paz')->utc());
        $f = $this->fixture();
        $flour = $this->inventoryItem($f['company'], $f['unit'], 'Harina');
        $cheese = $this->inventoryItem($f['company'], $f['unit'], 'Queso');
        $sauce = $this->inventoryItem($f['company'], $f['unit'], 'Salsa');

        $this->consumption($f, '2.000', [
            [$flour, '3.000', 'known', '12.000000'],
        ]);
        $this->consumption($f, '1.000', [
            [$flour, '1.000', 'known', '5.000000'],
            [$cheese, '2.000', 'unknown', null],
        ]);
        $this->consumption($f, '1.000', [
            [$cheese, '1.000', 'unknown', null],
        ]);
        $this->consumption($f, '1.000', [
            [$sauce, '1.000', 'known', '0.000000'],
        ]);

        $range = $this->range('today');
        $internal = app(InternalConsumptionReportService::class)->period($f['company'], $f['branch'], $range);
        $summary = app(ReportsOverviewService::class)->data($f['company'], $f['branch'], $range);
        $profitability = app(ProfitabilityReportService::class)->data($f['company'], $f['branch'], $range);

        $this->assertSame(4, $internal['consumptions']);
        $this->assertSame('5.000', $internal['pizzas']);
        $this->assertSame('17.00', $internal['known_cost']);
        $this->assertTrue($internal['has_incomplete_costs']);
        $this->assertSame(['known' => 2, 'partial' => 1, 'unknown' => 1, 'known_zero' => 1], $internal['cost_status_counts']);
        $this->assertSame('4.000', $internal['by_item']->firstWhere('name', 'Harina')['quantity']);
        $this->assertSame(2, $internal['by_item']->firstWhere('name', 'Harina')['movements']);
        $this->assertSame('17.00', $internal['by_item']->firstWhere('name', 'Harina')['known_cost']);
        $this->assertSame('17.00', $summary['internalConsumptions']['known_cost']);
        $this->assertSame('0.00', $summary['sales']['sales_total']);
        $this->assertSame('0.00', $profitability['production_cost']);
        $this->assertSame('17.00', $profitability['internal_consumptions']['known_cost']);
        $this->assertSame('-17.00', $profitability['estimated_result']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('cash_movements', 0);

        $this->actingInContext($f)->get(route('reports.index'))->assertOk()
            ->assertSee('Consumos internos')->assertSee('Hay costos incompletos');
        $this->actingInContext($f)->get(route('reports.inventory'))->assertOk()
            ->assertSee('Consumos internos del periodo')->assertSee('Harina')->assertSee('Desconocido');
        $this->actingInContext($f)->get(route('reports.profitability'))->assertOk()
            ->assertSee('Consumos internos')->assertSee('no incluye todos los costos reales');
    }

    public function test_period_filters_branch_and_company_at_the_bolivia_midnight_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'America/La_Paz')->utc());
        $f = $this->fixture();
        $item = $this->inventoryItem($f['company'], $f['unit'], 'Harina');
        $inside = CarbonImmutable::parse('2026-10-11 00:05:00', 'America/La_Paz')->utc();
        $outside = CarbonImmutable::parse('2026-10-10 23:55:00', 'America/La_Paz')->utc();

        $this->consumption($f, '1.000', [[$item, '1.000', 'known', '2.000000']], $inside);
        $this->consumption($f, '9.000', [[$item, '9.000', 'known', '18.000000']], $outside);

        $otherBranch = Branch::factory()->for($f['company'])->create();
        $otherBranchFixture = [...$f, 'branch' => $otherBranch];
        $this->consumption($otherBranchFixture, '8.000', [[$item, '8.000', 'known', '16.000000']], $inside);

        $other = $this->fixture();
        $otherItem = $this->inventoryItem($other['company'], $other['unit'], 'Harina ajena');
        $this->consumption($other, '7.000', [[$otherItem, '7.000', 'known', '14.000000']], $inside);

        $data = app(InternalConsumptionReportService::class)->period(
            $f['company'],
            $f['branch'],
            $this->range('custom', '2026-10-11', '2026-10-11'),
        );

        $this->assertSame(1, $data['consumptions']);
        $this->assertSame('1.000', $data['pizzas']);
        $this->assertSame('2.00', $data['known_cost']);
    }

    public function test_cancelled_consumption_and_reversed_movements_are_excluded_from_every_internal_total(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'America/La_Paz')->utc());
        $f = $this->fixture();
        $item = $this->inventoryItem($f['company'], $f['unit'], 'Harina');
        $consumption = $this->consumption($f, '2.000', [[$item, '3.000', 'known', '12.000000']]);
        $original = $consumption->components()->firstOrFail()->movement;

        InventoryMovement::factory()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'inventory_item_id' => $item->id,
            'type' => InventoryMovementType::Reversal,
            'quantity' => $original->quantity,
            'unit_cost' => $original->unit_cost,
            'total_cost' => $original->total_cost,
            'reference_type' => InventoryMovement::class,
            'reference_id' => $original->id,
            'metadata' => ['direction' => 1],
            'occurred_at' => now(),
            'created_by' => $f['owner']->id,
            'reversal_of_id' => $original->id,
        ]);
        $consumption->forceFill(['status' => InternalConsumptionStatus::Cancelled, 'cancelled_at' => now()])->save();

        $internal = app(InternalConsumptionReportService::class)->period($f['company'], $f['branch'], $this->range());
        $profitability = app(ProfitabilityReportService::class)->data($f['company'], $f['branch'], $this->range());

        $this->assertSame(0, $internal['consumptions']);
        $this->assertSame('0.000', $internal['pizzas']);
        $this->assertSame('0.00', $internal['known_cost']);
        $this->assertCount(0, $internal['by_item']);
        $this->assertSame('0.00', $profitability['estimated_result']);
        $this->assertDatabaseHas('inventory_movements', ['reversal_of_id' => $original->id, 'type' => InventoryMovementType::Reversal->value]);
    }

    public function test_commercial_reports_are_unchanged_when_there_are_no_internal_consumptions(): void
    {
        $f = $this->fixture();
        $range = $this->range();

        $summary = app(ReportsOverviewService::class)->data($f['company'], $f['branch'], $range);
        $profitability = app(ProfitabilityReportService::class)->data($f['company'], $f['branch'], $range);

        $this->assertSame('0.00', $summary['sales']['sales_total']);
        $this->assertSame('0.00', $summary['expenses']['total']);
        $this->assertSame('0.00', $profitability['estimated_result']);
        $this->assertSame(0, $summary['internalConsumptions']['consumptions']);
        $this->assertFalse($summary['internalConsumptions']['has_incomplete_costs']);
    }

    private function fixture(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->create(['role' => MembershipRole::Owner]);
        $unit = Unit::factory()->for($company)->create(['name' => 'Kilogramo', 'symbol' => 'kg', 'type' => UnitType::Weight]);
        $product = Product::factory()->for($company)->create(['name' => 'Pizza', 'type' => ProductType::Pizza]);
        $variant = ProductVariant::factory()->for($company)->for($product)->create(['name' => 'Grande']);

        return compact('company', 'branch', 'owner', 'unit', 'variant');
    }

    private function inventoryItem(Company $company, Unit $unit, string $name): InventoryItem
    {
        $ingredient = Ingredient::factory()->for($company)->for($unit)->create(['name' => $name]);

        return InventoryItem::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'ingredient_id' => $ingredient->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /** @param list<array{0:InventoryItem,1:string,2:string,3:?string}> $components */
    private function consumption(array $f, string $pizzas, array $components, ?CarbonImmutable $confirmedAt = null): InternalConsumption
    {
        $confirmedAt ??= now()->toImmutable();
        $knownCost = collect($components)->where(2, 'known')->reduce(
            fn (BigDecimal $total, array $component): BigDecimal => $total->plus($component[3] ?? '0'),
            BigDecimal::zero(),
        );
        $knownCount = collect($components)->where(2, 'known')->count();
        $unknownCount = count($components) - $knownCount;
        $status = match (true) {
            $unknownCount === 0 => 'known',
            $knownCount === 0 => 'unknown',
            default => 'partial',
        };
        $consumption = InternalConsumption::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'code' => 'CI-'.Str::ulid(),
            'reason' => InternalConsumptionReason::Marketing,
            'status' => InternalConsumptionStatus::Confirmed,
            'total_cost' => (string) $knownCost->toScale(6),
            'cost_status' => $status,
            'idempotency_key' => (string) Str::ulid(),
            'created_by' => $f['owner']->id,
            'confirmed_at' => $confirmedAt,
        ]);
        $internalItem = InternalConsumptionItem::query()->create([
            'company_id' => $f['company']->id,
            'branch_id' => $f['branch']->id,
            'internal_consumption_id' => $consumption->id,
            'product_variant_id' => $f['variant']->id,
            'quantity' => $pizzas,
            'fulfillment_type' => OrderType::DineIn,
            'product_name_snapshot' => 'Pizza',
            'variant_name_snapshot' => 'Grande',
            'configuration_snapshot' => ['size_key' => 'familiar'],
        ]);

        foreach ($components as [$item, $quantity, $costStatus, $totalCost]) {
            $movement = InventoryMovement::factory()->create([
                'company_id' => $f['company']->id,
                'branch_id' => $f['branch']->id,
                'inventory_item_id' => $item->id,
                'type' => InventoryMovementType::InternalConsumption,
                'quantity' => $quantity,
                'unit_cost' => $costStatus === 'known' ? '1.000000' : '0.000000',
                'total_cost' => $totalCost ?? '0.000000',
                'reference_type' => InternalConsumptionItem::class,
                'reference_id' => $internalItem->id,
                'occurred_at' => $confirmedAt,
                'created_by' => $f['owner']->id,
            ]);
            InternalConsumptionComponent::query()->create([
                'company_id' => $f['company']->id,
                'branch_id' => $f['branch']->id,
                'internal_consumption_id' => $consumption->id,
                'internal_consumption_item_id' => $internalItem->id,
                'inventory_item_id' => $item->id,
                'unit_id' => $item->unit_id,
                'inventory_movement_id' => $movement->id,
                'inventory_item_name_snapshot' => $item->name,
                'unit_symbol_snapshot' => $item->unit->symbol,
                'quantity' => $quantity,
                'unit_cost' => $costStatus === 'known' ? $movement->unit_cost : null,
                'total_cost' => $costStatus === 'known' ? $totalCost : null,
                'cost_status' => $costStatus,
            ]);
        }

        return $consumption->fresh(['components.movement']);
    }

    private function range(string $preset = 'today', ?string $from = null, ?string $to = null)
    {
        return app(ReportDateRangeService::class)->from(array_filter([
            'preset' => $preset,
            'date_from' => $from,
            'date_to' => $to,
        ]));
    }

    private function actingInContext(array $f): static
    {
        return $this->actingAs($f['owner'])->withSession([
            'active_company_id' => $f['company']->id,
            'active_branch_id' => $f['branch']->id,
        ]);
    }
}
