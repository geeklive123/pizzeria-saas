<?php

namespace App\Http\Controllers;

use App\Actions\SavePromotionAction;
use App\Http\Requests\PromotionRequest;
use App\Models\InventoryItem;
use App\Models\Promotion;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Promotion::class);
        $promotions = Promotion::query()->forCompany($this->company())
            ->with(['productVariant.product', 'components.inventoryItem.unit'])
            ->latest()->paginate(20);

        return view('promotions.index', compact('promotions'));
    }

    public function create(): View
    {
        Gate::authorize('create', Promotion::class);

        return $this->form(new Promotion(['is_active' => true]));
    }

    public function store(PromotionRequest $request, SavePromotionAction $action): RedirectResponse
    {
        Gate::authorize('create', Promotion::class);
        try {
            $action->execute($this->company(), $request->validated(), $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['promotion' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('promotions.index')->with('success', 'Promoción creada correctamente.');
    }

    public function edit(string $promotion): View
    {
        $promotion = $this->promotion($promotion);
        Gate::authorize('update', $promotion);

        return $this->form($promotion->load(['productVariant.product', 'components.inventoryItem']));
    }

    public function update(PromotionRequest $request, string $promotion, SavePromotionAction $action): RedirectResponse
    {
        $promotion = $this->promotion($promotion);
        Gate::authorize('update', $promotion);
        try {
            $action->execute($this->company(), $request->validated(), $request->user(), $promotion);
        } catch (DomainException $exception) {
            return back()->withErrors(['promotion' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('promotions.index')->with('success', 'Promoción actualizada correctamente.');
    }

    public function toggle(Request $request, string $promotion, SavePromotionAction $action): RedirectResponse
    {
        $promotion = $this->promotion($promotion)->load(['productVariant.product', 'components.inventoryItem']);
        Gate::authorize('update', $promotion);
        $action->execute($this->company(), [
            'name' => $promotion->productVariant->product->name,
            'description' => $promotion->productVariant->product->description,
            'price' => $promotion->productVariant->price,
            'is_active' => ! $promotion->is_active,
            'starts_at' => $promotion->starts_at,
            'ends_at' => $promotion->ends_at,
            'components' => $promotion->components->map(fn ($component): array => [
                'inventory_item_ulid' => $component->inventoryItem->ulid,
                'quantity' => $component->quantity,
            ])->all(),
        ], $request->user(), $promotion);

        return back()->with('success', 'Estado de la promoción actualizado.');
    }

    private function form(Promotion $promotion): View
    {
        $inventoryItems = InventoryItem::query()->forCompany($this->company())->where('is_active', true)
            ->with('unit')->orderBy('name')->get();

        return view('promotions.form', compact('promotion', 'inventoryItems'));
    }

    private function promotion(string $ulid): Promotion
    {
        return Promotion::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
