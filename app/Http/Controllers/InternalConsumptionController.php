<?php

namespace App\Http\Controllers;

use App\Actions\CancelInternalConsumptionAction;
use App\Actions\CreateInternalConsumptionAction;
use App\Enums\InternalConsumptionReason;
use App\Http\Requests\CancelInternalConsumptionRequest;
use App\Http\Requests\PreviewInternalConsumptionRequest;
use App\Http\Requests\PrintInternalConsumptionRequest;
use App\Http\Requests\StoreInternalConsumptionRequest;
use App\Models\InternalConsumption;
use App\Services\InternalConsumptionPreparationService;
use App\Services\InternalConsumptionReportService;
use App\Services\OrderPosCatalogService;
use App\Services\ThermalPrintingService;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InternalConsumptionController extends Controller
{
    public function index(Request $request, InternalConsumptionReportService $reports): View|RedirectResponse
    {
        Gate::authorize('viewAny', InternalConsumption::class);
        $filters = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $consumptions = InternalConsumption::query()->forCompany($this->company())
            ->where('branch_id', $this->branch()->getKey())->with(['branch', 'createdBy', 'items', 'components:id,internal_consumption_id,cost_status'])
            ->latest('confirmed_at')->paginate(20);
        $report = Gate::allows('report', InternalConsumption::class) ? $reports->monthly($this->company(), $this->branch(), $filters['month'] ?? null) : null;

        return view('internal-consumptions.index', compact('consumptions', 'report'));
    }

    public function create(OrderPosCatalogService $catalog): View
    {
        Gate::authorize('create', InternalConsumption::class);
        ['pizzaVariants' => $pizzaVariants, 'modifierOptions' => $modifierOptions, 'toppingOptions' => $toppingOptions] = $catalog->forOrderScreen($this->company(), $this->branch());

        return view('internal-consumptions.create', [
            'pizzaVariants' => $pizzaVariants,
            'modifierOptions' => $modifierOptions,
            'toppingOptions' => $toppingOptions,
            'reasons' => InternalConsumptionReason::cases(),
            'idempotencyKey' => (string) Str::ulid(),
        ]);
    }

    public function preview(PreviewInternalConsumptionRequest $request, InternalConsumptionPreparationService $preparation): View|RedirectResponse
    {
        Gate::authorize('create', InternalConsumption::class);
        try {
            $data = $request->validated();
            $preview = $preparation->prepare($this->company(), $this->branch(), $data['items']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['items' => $exception->getMessage()]);
        }

        return view('internal-consumptions.confirm', [
            'data' => $data,
            'preview' => $preview,
            'reason' => InternalConsumptionReason::from($data['reason']),
            'payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function store(StoreInternalConsumptionRequest $request, CreateInternalConsumptionAction $action): RedirectResponse
    {
        Gate::authorize('create', InternalConsumption::class);
        try {
            $data = json_decode(Crypt::decryptString($request->validated('payload')), true, flags: JSON_THROW_ON_ERROR);
            $consumption = $action->execute(
                $this->company(), $this->branch(), $request->user(), InternalConsumptionReason::from($data['reason']),
                $data['notes'] ?? null, $data['idempotency_key'], $data['items'],
            );
        } catch (DecryptException|DomainException|\JsonException $exception) {
            return redirect()->route('internal-consumptions.create')->withErrors(['items' => $exception->getMessage()]);
        }

        return redirect()->route('internal-consumptions.show', $consumption->ulid)->with('success', 'Consumo interno registrado. La comanda no se imprimió automáticamente.');
    }

    public function show(string $consumption): View
    {
        $consumption = $this->consumption($consumption)->load([
            'branch', 'createdBy', 'items.components', 'components.movement.reversals',
            'cancellation.cancelledBy', 'printAttempts' => fn ($query) => $query->latest('attempted_at'),
        ]);
        Gate::authorize('view', $consumption);

        return view('internal-consumptions.show', ['consumption' => $consumption, 'printIdempotencyKey' => (string) Str::ulid()]);
    }

    public function print(PrintInternalConsumptionRequest $request, string $consumption, ThermalPrintingService $printing): RedirectResponse
    {
        $consumption = $this->consumption($consumption);
        Gate::authorize('print', $consumption);
        try {
            $attempt = $printing->internalConsumption($consumption, $request->user(), $request->validated('idempotency_key'));
        } catch (DomainException $exception) {
            return back()->withErrors(['printing' => $exception->getMessage()]);
        }

        return back()->with($attempt->status->value === 'pending' ? 'success' : 'error', $attempt->status->value === 'pending' ? 'Comanda enviada a la cola de cocina.' : $attempt->error_message);
    }

    public function cancel(CancelInternalConsumptionRequest $request, string $consumption, CancelInternalConsumptionAction $action): RedirectResponse
    {
        $consumption = $this->consumption($consumption);
        Gate::authorize('cancel', $consumption);
        try {
            $action->execute(
                $consumption,
                $request->user(),
                $request->validated('reason'),
                $request->boolean('confirm_not_prepared'),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['cancellation' => $exception->getMessage()]);
        }

        return back()->with('success', 'Consumo interno anulado y existencias revertidas.');
    }

    private function consumption(string $ulid): InternalConsumption
    {
        return InternalConsumption::query()->forCompany($this->company())->where('branch_id', $this->branch()->getKey())
            ->where('ulid', $ulid)->firstOrFail();
    }
}
