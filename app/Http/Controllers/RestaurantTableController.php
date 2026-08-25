<?php

namespace App\Http\Controllers;

use App\Actions\OpenTableOrderAction;
use App\Actions\SaveRestaurantTableAction;
use App\Http\Requests\RestaurantTableRequest;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Services\RestaurantTableQueryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RestaurantTableController extends Controller
{
    public function index(RestaurantTableQueryService $tables): View
    {
        Gate::authorize('viewAny', RestaurantTable::class);
        $tables = $tables->forManagement($this->company(), $this->branch(), Gate::allows('create', RestaurantTable::class));

        return view('tables.index', compact('tables'));
    }

    public function create(): View
    {
        Gate::authorize('create', RestaurantTable::class);

        return view('tables.form', ['table' => null]);
    }

    public function store(RestaurantTableRequest $request, SaveRestaurantTableAction $action): RedirectResponse
    {
        Gate::authorize('create', RestaurantTable::class);
        $action->execute($this->company(), $this->branch(), $request->user(), $request->validated());

        return redirect()->route('tables.index')->with('success', 'Mesa creada.');
    }

    public function edit(string $table): View
    {
        $table = $this->table($table);
        Gate::authorize('update', $table);

        return view('tables.form', compact('table'));
    }

    public function update(RestaurantTableRequest $request, string $table, SaveRestaurantTableAction $action): RedirectResponse
    {
        $table = $this->table($table);
        Gate::authorize('update', $table);

        try {
            $action->execute($this->company(), $this->branch(), $request->user(), $request->validated(), $table);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['table' => $exception->getMessage()]);
        }

        return redirect()->route('tables.index')->with('success', 'Mesa actualizada.');
    }

    public function open(string $table, OpenTableOrderAction $action): RedirectResponse
    {
        Gate::authorize('create', Order::class);
        $table = RestaurantTable::query()->forCompany($this->company())->forBranch($this->branch())->where('ulid', $table)->firstOrFail();
        try {
            $order = $action->execute($this->company(), $this->branch(), $table, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['table' => $exception->getMessage()]);
        }

        return redirect()->route('orders.show', $order->ulid)->with('success', "Cuenta {$order->formattedNumber()} abierta.");
    }

    private function table(string $ulid): RestaurantTable
    {
        return RestaurantTable::query()->forCompany($this->company())->forBranch($this->branch())->where('ulid', $ulid)->firstOrFail();
    }
}
