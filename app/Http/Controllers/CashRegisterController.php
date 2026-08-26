<?php

namespace App\Http\Controllers;

use App\Actions\SaveCashRegisterAction;
use App\Http\Requests\CashRegisterRequest;
use App\Models\CashRegister;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function index(): View
    {
        Gate::authorize('create', CashRegister::class);
        $registers = CashRegister::query()->forCompany($this->company())->forBranch($this->branch())
            ->with('activeSession.openedBy:id,name')->orderBy('name')->paginate(20);

        return view('cash-registers.index', compact('registers'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', CashRegister::class);
        $register = new CashRegister(['name' => 'Caja Principal', 'is_active' => true]);
        $onboarding = $request->boolean('onboarding');

        return view('cash-registers.form', compact('register', 'onboarding'));
    }

    public function store(CashRegisterRequest $request, SaveCashRegisterAction $action): RedirectResponse
    {
        Gate::authorize('create', CashRegister::class);
        $register = $action->execute($this->company(), $this->branch(), $request->user(), $request->validated());

        if ($request->boolean('onboarding')) {
            return redirect()->route('cash.open.form')
                ->with('success', 'Caja creada. Ya puedes abrir el primer turno.');
        }

        return redirect()->route('cash-registers.index')->with('success', 'Caja creada correctamente.');
    }

    public function edit(string $cash_register): View
    {
        $register = $this->register($cash_register);
        Gate::authorize('update', $register);
        $onboarding = false;

        return view('cash-registers.form', compact('register', 'onboarding'));
    }

    public function update(
        CashRegisterRequest $request,
        string $cash_register,
        SaveCashRegisterAction $action,
    ): RedirectResponse {
        $register = $this->register($cash_register);
        Gate::authorize('update', $register);

        try {
            $action->execute($this->company(), $this->branch(), $request->user(), $request->validated(), $register);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['cash_register' => $exception->getMessage()]);
        }

        return redirect()->route('cash-registers.index')->with('success', 'Caja actualizada correctamente.');
    }

    public function toggle(Request $request, string $cash_register, SaveCashRegisterAction $action): RedirectResponse
    {
        $register = $this->register($cash_register);
        Gate::authorize('update', $register);

        try {
            $action->execute($this->company(), $this->branch(), $request->user(), [
                'name' => $register->name,
                'is_active' => ! $register->is_active,
            ], $register);
        } catch (DomainException $exception) {
            return back()->withErrors(['cash_register' => $exception->getMessage()]);
        }

        return back()->with('success', 'Estado de la caja actualizado.');
    }

    private function register(string $ulid): CashRegister
    {
        return CashRegister::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('ulid', $ulid)->firstOrFail();
    }
}
