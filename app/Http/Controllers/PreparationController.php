<?php

namespace App\Http\Controllers;

use App\Actions\ProducePreparationAction;
use App\Actions\ReversePreparationProductionAction;
use App\Actions\SavePreparationAction;
use App\Http\Requests\PreparationRequest;
use App\Http\Requests\ProducePreparationRequest;
use App\Models\InventoryItem;
use App\Models\Preparation;
use App\Models\PreparationProduction;
use App\Services\PreparationAvailabilityService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PreparationController extends Controller
{
    public function index(PreparationAvailabilityService $availability): View
    {
        Gate::authorize('viewAny', Preparation::class);
        $preparations = Preparation::query()->forCompany($this->company())
            ->with(['outputInventoryItem.unit', 'components.inventoryItem.unit'])->orderBy('name')->get();
        $preparations->each(fn (Preparation $preparation) => $preparation->setAttribute(
            'current_availability', $availability->calculate($preparation, $this->branch()),
        ));

        return view('preparations.index', compact('preparations'));
    }

    public function create(): View
    {
        Gate::authorize('create', Preparation::class);

        return $this->form(new Preparation(['is_active' => true]));
    }

    public function store(PreparationRequest $request, SavePreparationAction $action): RedirectResponse
    {
        Gate::authorize('create', Preparation::class);
        $preparation = $action->execute(
            $this->company(), $request->user(), $request->validated('name'),
            $this->item($request->integer('output_inventory_item_id')),
            $request->validated('theoretical_yield'), $request->boolean('is_active'),
            $request->validated('components'),
        );

        return redirect()->route('preparations.show', $preparation->ulid)->with('success', 'Preparación creada correctamente.');
    }

    public function show(string $preparation, PreparationAvailabilityService $availability): View
    {
        $preparation = $this->preparation($preparation)->load([
            'outputInventoryItem.unit', 'components.inventoryItem.unit',
            'productions' => fn ($query) => $query->where('branch_id', $this->branch()->getKey())
                ->with(['createdBy', 'reversedBy'])->latest('produced_at')->limit(30),
        ]);
        Gate::authorize('view', $preparation);
        $currentAvailability = $availability->calculate($preparation, $this->branch());

        return view('preparations.show', compact('preparation', 'currentAvailability'));
    }

    public function edit(string $preparation): View
    {
        $preparation = $this->preparation($preparation)->load('components');
        Gate::authorize('update', $preparation);

        return $this->form($preparation);
    }

    public function update(PreparationRequest $request, string $preparation, SavePreparationAction $action): RedirectResponse
    {
        $preparation = $this->preparation($preparation);
        Gate::authorize('update', $preparation);
        $preparation = $action->execute(
            $this->company(), $request->user(), $request->validated('name'),
            $this->item($request->integer('output_inventory_item_id')),
            $request->validated('theoretical_yield'), $request->boolean('is_active'),
            $request->validated('components'), $preparation,
        );

        return redirect()->route('preparations.show', $preparation->ulid)->with('success', 'Preparación actualizada.');
    }

    public function produce(
        ProducePreparationRequest $request,
        string $preparation,
        ProducePreparationAction $action,
    ): RedirectResponse {
        $preparation = $this->preparation($preparation);
        Gate::authorize('produce', $preparation);
        try {
            $action->execute(
                $this->company(), $this->branch(), $preparation, $request->integer('lots'),
                $request->user(), $request->validated('actual_yield'),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['production' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', 'Producción registrada e inventario actualizado.');
    }

    public function reverse(Request $request, string $preparation, string $production, ReversePreparationProductionAction $action): RedirectResponse
    {
        $preparation = $this->preparation($preparation);
        Gate::authorize('reverse', $preparation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $production = PreparationProduction::query()->forCompany($this->company())
            ->where('branch_id', $this->branch()->getKey())->where('preparation_id', $preparation->getKey())
            ->where('ulid', $production)->firstOrFail();
        try {
            $action->execute($production, $request->user(), $data['reason']);
        } catch (DomainException $exception) {
            return back()->withErrors(['reversal' => $exception->getMessage()]);
        }

        return back()->with('success', 'Producción revertida mediante movimientos compensatorios.');
    }

    private function form(Preparation $preparation): View
    {
        $items = InventoryItem::query()->forCompany($this->company())->whereNotNull('ingredient_id')
            ->where('is_active', true)->with('unit')->orderBy('name')->get();

        return view('preparations.form', compact('preparation', 'items'));
    }

    private function preparation(string $ulid): Preparation
    {
        return Preparation::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }

    private function item(int $id): InventoryItem
    {
        return InventoryItem::query()->forCompany($this->company())->whereKey($id)->firstOrFail();
    }
}
