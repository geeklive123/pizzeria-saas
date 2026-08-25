<?php

namespace App\Http\Controllers;

use App\Actions\PostPurchaseAction;
use App\Actions\ReversePurchaseAction;
use App\Actions\SavePurchaseDraftAction;
use App\Enums\PurchaseStatus;
use App\Http\Requests\PurchaseRequest;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Unit;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Purchase::class);
        $purchases = Purchase::query()->forCompany($this->company())->where('branch_id', $this->branch()->getKey())
            ->with(['createdBy', 'items'])->latest('purchased_at')->paginate(15);
        $purchases->getCollection()->each(function (Purchase $purchase): void {
            $purchase->setAttribute('display_total', (string) $purchase->items->reduce(
                fn (BigDecimal $sum, $item) => $sum->plus($item->total_cost),
                BigDecimal::zero(),
            ));
        });

        return view('purchases.index', compact('purchases'));
    }

    public function create(): View
    {
        Gate::authorize('create', Purchase::class);

        return $this->form(new Purchase(['purchased_at' => now(), 'status' => PurchaseStatus::Draft]));
    }

    public function store(PurchaseRequest $request, SavePurchaseDraftAction $action): RedirectResponse
    {
        Gate::authorize('create', Purchase::class);
        try {
            $purchase = $action->execute($this->company(), $this->branch(), $request->user(), $request->validated(), $request->validated('items'));
        } catch (DomainException $exception) {
            return back()->withErrors(['purchase' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('purchases.show', $purchase->ulid)->with('success', 'Compra guardada como borrador.');
    }

    public function show(string $purchase): View
    {
        $purchase = $this->purchase($purchase)->load(['branch', 'createdBy', 'items.inventoryItem.unit', 'items.inputUnit']);
        Gate::authorize('view', $purchase);
        $total = (string) $purchase->items->reduce(fn (BigDecimal $sum, $item) => $sum->plus($item->total_cost), BigDecimal::zero());

        return view('purchases.show', compact('purchase', 'total'));
    }

    public function edit(string $purchase): View
    {
        $purchase = $this->purchase($purchase)->load('items');
        Gate::authorize('update', $purchase);
        abort_unless($purchase->status === PurchaseStatus::Draft, 409, 'Una compra confirmada no puede editarse.');

        return $this->form($purchase);
    }

    public function update(PurchaseRequest $request, string $purchase, SavePurchaseDraftAction $action): RedirectResponse
    {
        $purchase = $this->purchase($purchase);
        Gate::authorize('update', $purchase);
        try {
            $action->execute($this->company(), $this->branch(), $request->user(), $request->validated(), $request->validated('items'), $purchase);
        } catch (DomainException $exception) {
            return back()->withErrors(['purchase' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('purchases.show', $purchase->ulid)->with('success', 'Borrador actualizado.');
    }

    public function post(string $purchase, PostPurchaseAction $action, Request $request): RedirectResponse
    {
        $purchase = $this->purchase($purchase);
        Gate::authorize('update', $purchase);

        try {
            $action->execute($purchase, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['purchase' => $exception->getMessage()]);
        }

        return back()->with('success', 'Compra confirmada e inventario actualizado.');
    }

    public function reverse(string $purchase, ReversePurchaseAction $action, Request $request): RedirectResponse
    {
        $purchase = $this->purchase($purchase);
        Gate::authorize('update', $purchase);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $action->execute($purchase, $request->user(), $data['reason']);
        } catch (DomainException $exception) {
            return back()->withErrors(['purchase' => $exception->getMessage()]);
        }

        return back()->with('success', 'Compra revertida mediante movimientos compensatorios.');
    }

    private function form(Purchase $purchase): View
    {
        $items = InventoryItem::query()->forCompany($this->company())->where('is_active', true)->with('unit')->orderBy('name')->get();
        $units = Unit::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();

        return view('purchases.form', compact('purchase', 'items', 'units'));
    }

    private function purchase(string $ulid): Purchase
    {
        return Purchase::query()->forCompany($this->company())->where('branch_id', $this->branch()->getKey())->where('ulid', $ulid)->firstOrFail();
    }
}
