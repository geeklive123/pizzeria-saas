<?php

namespace App\Http\Controllers;

use App\Actions\SaveSupplierAction;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Supplier::class);
        $suppliers = Supplier::query()->forCompany($this->company())->withCount('expenses')->orderBy('name')->paginate(20);

        return view('suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        Gate::authorize('create', Supplier::class);

        return view('suppliers.form', ['supplier' => new Supplier(['is_active' => true])]);
    }

    public function store(SupplierRequest $request, SaveSupplierAction $action): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);
        $action->execute($this->company(), $request->user(), $request->validated());

        return redirect()->route('suppliers.index')->with('success', 'Proveedor creado.');
    }

    public function edit(string $supplier): View
    {
        $supplier = $this->supplier($supplier);
        Gate::authorize('update', $supplier);

        return view('suppliers.form', compact('supplier'));
    }

    public function update(SupplierRequest $request, string $supplier, SaveSupplierAction $action): RedirectResponse
    {
        $supplier = $this->supplier($supplier);
        Gate::authorize('update', $supplier);
        $action->execute($this->company(), $request->user(), $request->validated(), $supplier);

        return redirect()->route('suppliers.index')->with('success', 'Proveedor actualizado.');
    }

    public function toggle(Request $request, string $supplier, SaveSupplierAction $action): RedirectResponse
    {
        $supplier = $this->supplier($supplier);
        Gate::authorize('update', $supplier);
        $action->execute($this->company(), $request->user(), ['is_active' => ! $supplier->is_active], $supplier);

        return back()->with('success', 'Estado del proveedor actualizado.');
    }

    private function supplier(string $ulid): Supplier
    {
        return Supplier::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
    }
}
