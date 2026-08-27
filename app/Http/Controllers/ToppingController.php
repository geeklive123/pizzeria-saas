<?php

namespace App\Http\Controllers;

use App\Actions\SaveToppingAction;
use App\Enums\ProductModifierPurpose;
use App\Http\Requests\ToppingRequest;
use App\Models\InventoryItem;
use App\Models\ModifierOption;
use App\Services\ToppingSizeCatalogService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ToppingController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ModifierOption::class);
        $toppings = $this->query()->with(['inventoryItem.unit', 'sizeRules'])
            ->orderBy('sort_order')->orderBy('name')->paginate(20);

        return view('toppings.index', compact('toppings'));
    }

    public function create(): View
    {
        Gate::authorize('create', ModifierOption::class);
        $nextOrder = (int) $this->query()->max('sort_order') + 1;

        return $this->form(new ModifierOption(['is_active' => true, 'price_delta' => '0.00', 'sort_order' => $nextOrder]));
    }

    public function store(ToppingRequest $request, SaveToppingAction $action): RedirectResponse
    {
        Gate::authorize('create', ModifierOption::class);
        try {
            $action->execute($this->company(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withErrors(['topping' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('toppings.index')->with('success', 'Topping creado correctamente.');
    }

    public function edit(string $topping): View
    {
        $topping = $this->topping($topping);
        Gate::authorize('update', $topping);

        return $this->form($topping->load('sizeRules'));
    }

    public function update(ToppingRequest $request, string $topping, SaveToppingAction $action): RedirectResponse
    {
        $topping = $this->topping($topping);
        Gate::authorize('update', $topping);
        try {
            $action->execute($this->company(), $request->validated(), $topping);
        } catch (DomainException $exception) {
            return back()->withErrors(['topping' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('toppings.index')->with('success', 'Topping actualizado correctamente.');
    }

    public function toggle(Request $request, string $topping): RedirectResponse
    {
        $topping = $this->topping($topping);
        Gate::authorize('update', $topping);
        $topping->update(['is_active' => ! $topping->is_active]);

        return back()->with('success', 'Estado del topping actualizado.');
    }

    private function form(ModifierOption $topping): View
    {
        $inventoryItems = InventoryItem::query()->forCompany($this->company())
            ->with(['unit', 'ingredient', 'productVariant.product'])->orderBy('name')->get();
        $sizes = app(ToppingSizeCatalogService::class)->forCompany($this->company());

        return view('toppings.form', compact('topping', 'inventoryItems', 'sizes'));
    }

    private function topping(string $ulid): ModifierOption
    {
        return $this->query()->where('ulid', $ulid)->firstOrFail();
    }

    /** @return Builder<ModifierOption> */
    private function query(): Builder
    {
        return ModifierOption::query()->forCompany($this->company())
            ->whereHas('modifier', fn ($query) => $query->where('purpose', ProductModifierPurpose::ToppingCatalog));
    }
}
