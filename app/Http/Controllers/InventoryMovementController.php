<?php

namespace App\Http\Controllers;

use App\Enums\InventoryMovementType;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InventoryMovementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', InventoryMovement::class);
        $filters = $request->validate([
            'item' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = InventoryMovement::query()->forCompany($this->company())
            ->where('branch_id', $this->branch()->getKey())->with(['inventoryItem.unit', 'createdBy']);

        $query->when($filters['item'] ?? null, fn ($q, $ulid) => $q->whereHas('inventoryItem', fn ($items) => $items->where('ulid', $ulid)));
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $query->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date));
        $query->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date));

        $movements = $query->latest('occurred_at')->paginate(25)->withQueryString();
        $items = InventoryItem::query()->forCompany($this->company())->orderBy('name')->get();
        $types = InventoryMovementType::cases();

        return view('inventory.movements', compact('movements', 'items', 'types'));
    }
}
