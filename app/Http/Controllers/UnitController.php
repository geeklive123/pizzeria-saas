<?php

namespace App\Http\Controllers;

use App\Actions\InitializeStandardUnitsAction;
use App\Actions\SaveUnitAction;
use App\Enums\UnitType;
use App\Http\Requests\UnitRequest;
use App\Models\Unit;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Unit::class);
        $units = Unit::query()->forCompany($this->company())
            ->withCount(['ingredients', 'inventoryItems'])->orderBy('type')->orderBy('name')->paginate(20);

        return view('units.index', compact('units'));
    }

    public function create(): View
    {
        Gate::authorize('create', Unit::class);

        return view('units.form', [
            'unit' => new Unit(['is_active' => true, 'type' => UnitType::Unit]),
            'types' => UnitType::cases(),
        ]);
    }

    public function store(UnitRequest $request, SaveUnitAction $action): RedirectResponse
    {
        Gate::authorize('create', Unit::class);
        $action->execute($this->company(), $request->user(), $request->validated());

        return redirect()->route('units.index')->with('success', 'Unidad creada correctamente.');
    }

    public function edit(string $unit): View
    {
        $unit = $this->unit($unit);
        Gate::authorize('update', $unit);

        return view('units.form', ['unit' => $unit, 'types' => UnitType::cases()]);
    }

    public function update(UnitRequest $request, string $unit, SaveUnitAction $action): RedirectResponse
    {
        $unit = $this->unit($unit);
        Gate::authorize('update', $unit);
        try {
            $action->execute($this->company(), $request->user(), $request->validated(), $unit);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['unit' => $exception->getMessage()]);
        }

        return redirect()->route('units.index')->with('success', 'Unidad actualizada correctamente.');
    }

    public function toggle(Request $request, string $unit, SaveUnitAction $action): RedirectResponse
    {
        $unit = $this->unit($unit);
        Gate::authorize('update', $unit);
        $action->execute($this->company(), $request->user(), [
            'name' => $unit->name,
            'symbol' => $unit->symbol,
            'type' => $unit->type->value,
            'is_active' => ! $unit->is_active,
        ], $unit);

        return back()->with('success', 'Estado de la unidad actualizado.');
    }

    public function initialize(Request $request, InitializeStandardUnitsAction $action): RedirectResponse
    {
        Gate::authorize('create', Unit::class);
        try {
            $action->execute($this->company(), $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['units' => $exception->getMessage()]);
        }

        return back()->with('success', 'Unidades estándar listas para usar.');
    }

    private function unit(string $id): Unit
    {
        return Unit::query()->forCompany($this->company())->whereKey($id)->firstOrFail();
    }
}
