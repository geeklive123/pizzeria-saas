<?php

namespace App\Http\Controllers;

use App\Actions\RegisterExpiredBatchWasteAction;
use App\Actions\RegisterInventoryAdjustmentAction;
use App\Actions\RegisterOpeningStockAction;
use App\Actions\RegisterWasteAction;
use App\Actions\UpdateMinimumStockAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Http\Requests\ExpiredBatchWasteRequest;
use App\Http\Requests\InventoryOperationRequest;
use App\Http\Requests\MinimumStockRequest;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\Unit;
use App\Services\InventoryOverviewService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(InventoryOverviewService $overview): View
    {
        Gate::authorize('viewAny', InventoryItem::class);
        $items = $overview->items($this->company(), $this->branch());
        $totalValue = $overview->totalValue($items);

        return view('inventory.index', compact('items', 'totalValue'));
    }

    public function show(string $item, InventoryOverviewService $overview): View
    {
        $item = $this->item($item)->load([
            'unit',
            'ingredient',
            'productVariant.product',
            'inventoryStocks' => fn ($query) => $query->where('branch_id', $this->branch()->getKey()),
            'inventoryBatches' => fn ($query) => $query->where('branch_id', $this->branch()->getKey())
                ->orderByDesc('received_at'),
            'inventoryReservations' => fn ($query) => $query->where('branch_id', $this->branch()->getKey())
                ->where('status', InventoryReservationStatus::Reserved->value),
            'inventoryMovements' => fn ($query) => $query->where('branch_id', $this->branch()->getKey())
                ->latest('occurred_at')->limit(1),
        ]);
        $overview->decorate($item);
        Gate::authorize('view', $item);
        $units = Unit::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();
        $stock = $item->inventoryStocks()->where('branch_id', $this->branch()->getKey())->first();
        $hasOpeningMovement = $item->inventoryMovements()
            ->where('branch_id', $this->branch()->getKey())
            ->where('type', InventoryMovementType::Opening)
            ->exists();
        $movements = $item->inventoryMovements()->where('branch_id', $this->branch()->getKey())
            ->with('createdBy')->latest('occurred_at')->paginate(15);

        return view('inventory.show', compact('item', 'units', 'stock', 'hasOpeningMovement', 'movements'));
    }

    public function updateMinimum(
        MinimumStockRequest $request,
        string $item,
        UpdateMinimumStockAction $action,
    ): RedirectResponse {
        $item = $this->item($item);
        Gate::authorize('update', $item);

        try {
            $action->execute(
                $this->company(),
                $this->branch(),
                $item,
                $request->validated('minimum_quantity'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['minimum_quantity' => $exception->getMessage()]);
        }

        return back()->with('success', 'Stock mínimo actualizado.');
    }

    public function operate(
        InventoryOperationRequest $request,
        string $item,
        RegisterOpeningStockAction $opening,
        RegisterInventoryAdjustmentAction $adjustment,
        RegisterWasteAction $waste,
    ): RedirectResponse {
        $item = $this->item($item)->load('unit');
        Gate::authorize('update', $item);
        $unit = Unit::query()->forCompany($this->company())->findOrFail($request->integer('unit_id'));

        try {
            match ($request->validated('operation')) {
                'opening' => $opening->execute(
                    $this->company(),
                    $this->branch(),
                    $item,
                    $request->validated('quantity'),
                    $unit,
                    $request->validated('unit_cost'),
                    $request->user(),
                    $request->validated('received_at')
                        ? CarbonImmutable::parse($request->validated('received_at'), 'America/La_Paz')->utc()
                        : null,
                    expiresAt: $request->validated('expires_at')
                        ? CarbonImmutable::parse($request->validated('expires_at'))->startOfDay()
                        : null,
                ),
                'adjustment' => $adjustment->execute($this->company(), $this->branch(), $item, InventoryMovementType::from($request->validated('direction')), $request->validated('quantity'), $unit, $request->validated('reason'), $request->user()),
                'waste' => $waste->execute($this->company(), $this->branch(), $item, $request->validated('quantity'), $unit, $request->validated('reason'), $request->user()),
            };
        } catch (DomainException $exception) {
            return back()->withErrors(['operation' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', 'Movimiento registrado correctamente.');
    }

    public function discardExpired(
        ExpiredBatchWasteRequest $request,
        string $item,
        string $batch,
        RegisterExpiredBatchWasteAction $action,
    ): RedirectResponse {
        $item = $this->item($item);
        Gate::authorize('update', $item);
        $batch = InventoryBatch::query()
            ->where('company_id', $this->company()->getKey())
            ->where('branch_id', $this->branch()->getKey())
            ->where('inventory_item_id', $item->getKey())
            ->where('ulid', $batch)
            ->firstOrFail();

        try {
            $action->execute(
                $this->company(),
                $this->branch(),
                $batch,
                $request->validated('quantity'),
                $request->validated('reason'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['expired_batch' => $exception->getMessage()]);
        }

        return back()->with('success', 'Stock vencido retirado y merma registrada.');
    }

    private function item(string $ulid): InventoryItem
    {
        return InventoryItem::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
