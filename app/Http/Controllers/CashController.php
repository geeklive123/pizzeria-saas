<?php

namespace App\Http\Controllers;

use App\Actions\CloseCashSessionAction;
use App\Actions\ManualCashMovementAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OwnerCashWithdrawalAction;
use App\Actions\RegisterExpenseAction;
use App\Actions\ResolveOperationalExpenseCategoryAction;
use App\Enums\CashMovementType;
use App\Enums\ExpenseDocumentType;
use App\Enums\LogoutReason;
use App\Enums\MembershipRole;
use App\Http\Requests\CashExpenseRequest;
use App\Http\Requests\CashMovementRequest;
use App\Http\Requests\CloseCashSessionRequest;
use App\Http\Requests\OpenCashSessionRequest;
use App\Http\Requests\OwnerCashWithdrawalRequest;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\UserAccessLogService;
use App\Support\CompanyContext;
use App\Support\UiFormatter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashController extends Controller
{
    public function index(CashSessionSummaryService $summaryService): View
    {
        Gate::authorize('viewAny', CashSession::class);
        $registers = CashRegister::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('is_active', true)
            ->with(['activeSession.openedBy:id,name', 'latestClosedSession'])
            ->orderBy('name')->get();
        $activeSessions = CashSession::query()->forCompany($this->company())->forBranch($this->branch())
            ->whereNotNull('active_cash_register_id')
            ->with(['cashRegister', 'openedBy', 'movements.createdBy', 'movements.authorizedBy'])
            ->orderBy('opened_at')->get();
        $session = $activeSessions->firstWhere('opened_by', request()->user()->id);
        $summary = $session ? $summaryService->calculate($session) : null;
        $movementBalances = $session ? $summaryService->movementBalances($session) : [];
        $formatter = UiFormatter::class;
        $cashRegisterClass = CashRegister::class;
        $withdrawalIdempotencyKeys = $activeSessions->mapWithKeys(fn (CashSession $item): array => [$item->id => (string) Str::ulid()]);
        $canCreateExpense = $session !== null && Gate::allows('create', Expense::class);

        return view('cash.index', compact('registers', 'activeSessions', 'session', 'summary', 'movementBalances', 'formatter', 'cashRegisterClass', 'withdrawalIdempotencyKeys', 'canCreateExpense'));
    }

    public function openForm(): View
    {
        Gate::authorize('viewAny', CashRegister::class);
        $registers = CashRegister::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('is_active', true)
            ->with(['activeSession.openedBy:id,name', 'latestClosedSession'])
            ->orderBy('name')->get();

        return view('cash.open', compact('registers'));
    }

    public function open(OpenCashSessionRequest $request, OpenCashSessionAction $action): RedirectResponse
    {
        $register = CashRegister::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('ulid', $request->string('register')->toString())->firstOrFail();
        Gate::authorize('open', $register);
        try {
            $action->execute($register, $request->validated('opening_amount'), $request->user(), $request->validated('notes'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['cash' => $exception->getMessage()]);
        }

        return redirect()->route('cash.current')->with('success', 'Turno de caja abierto correctamente.');
    }

    public function movement(CashMovementRequest $request, ManualCashMovementAction $action): RedirectResponse
    {
        $session = $this->openSession($request->user());
        Gate::authorize('update', $session);
        try {
            $action->execute(
                $session,
                CashMovementType::from($request->validated('type')),
                $request->validated('amount'),
                $request->validated('reason'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['cash' => $exception->getMessage()]);
        }

        return back()->with('success', 'Movimiento de caja registrado.');
    }

    public function expense(
        CashExpenseRequest $request,
        ResolveOperationalExpenseCategoryAction $resolveCategory,
        RegisterExpenseAction $action,
    ): RedirectResponse {
        Gate::authorize('create', Expense::class);
        $session = $this->findOpenSession($request->user());
        $category = $resolveCategory->execute($this->company());
        $data = [
            ...$request->validated(),
            'expense_category_id' => $category->id,
            'supplier_id' => null,
            'expense_date' => today()->toDateString(),
            'document_type' => ExpenseDocumentType::WithoutInvoice->value,
            'document_number' => null,
            'notes' => null,
        ];

        try {
            $action->execute($this->company(), $this->branch(), $request->user(), $data, $session);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }

        return redirect()->route('cash.current')->with('success', 'Egreso registrado correctamente.');
    }

    public function withdrawal(OwnerCashWithdrawalRequest $request, string $session, OwnerCashWithdrawalAction $action): RedirectResponse
    {
        $session = CashSession::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('ulid', $session)->firstOrFail();
        Gate::authorize('withdraw', $session);
        try {
            $action->execute(
                $session,
                $request->validated('amount'),
                $request->validated('reason'),
                $request->validated('observation'),
                $request->user(),
                $request->validated('idempotency_key'),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['cash' => $exception->getMessage()]);
        }

        return back()->with('success', 'Retiro del propietario registrado con autorización y trazabilidad.');
    }

    public function close(
        CloseCashSessionRequest $request,
        CloseCashSessionAction $action,
        UserAccessLogService $accessLogs,
    ): RedirectResponse {
        $session = $this->openSession($request->user());
        Gate::authorize('close', $session);
        try {
            $action->execute($session, $request->validated('counted_cash_amount'), $request->user(), $request->validated('closing_observation'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['cash' => $exception->getMessage()]);
        }

        if (app(CompanyContext::class)->membership()->role === MembershipRole::Cashier) {
            $accessLogs->finish($request, $request->user(), LogoutReason::CashClosed, $this->company()->getKey());
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return redirect()->route('cash.index')->with('success', 'Turno cerrado y diferencia guardada.');
    }

    private function openSession(User $user): CashSession
    {
        return $this->findOpenSession($user) ?? abort(404);
    }

    private function findOpenSession(User $user): ?CashSession
    {
        return CashSession::query()->forCompany($this->company())->forBranch($this->branch())
            ->whereNotNull('active_cash_register_id')->where('opened_by', $user->id)->first();
    }
}
