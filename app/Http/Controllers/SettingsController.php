<?php

namespace App\Http\Controllers;

use App\Actions\PrintTestPageAction;
use App\Actions\UpdatePrinterSettingsAction;
use App\Actions\UpdateSettingsAction;
use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Http\Requests\ChargeModeRequest;
use App\Http\Requests\SettingsRequest;
use App\Models\PrintAttempt;
use App\Models\PrinterSetting;
use App\Services\PrintAgentStatusService;
use App\Services\PrinterSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(PrinterSettingsService $printers, PrintAgentStatusService $agents): View
    {
        Gate::authorize('update', $this->company());
        $agent = $agents->current($this->company(), $this->branch());
        $printStats = [
            'pending' => PrintAttempt::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)
                ->whereIn('status', [PrintAttemptStatus::Pending->value, PrintAttemptStatus::Claimed->value])->count(),
            'failed' => PrintAttempt::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)
                ->where('status', PrintAttemptStatus::Failed->value)->count(),
        ];

        return view('settings.edit', [
            'company' => $this->company(),
            'branch' => $this->branch(),
            'kitchenPrinter' => $printers->get($this->company(), $this->branch(), PrinterPurpose::Kitchen),
            'ticketPrinter' => $printers->get($this->company(), $this->branch(), PrinterPurpose::CustomerTicket),
            'printAgent' => $agent,
            'printAgentOnline' => $agents->isOnline($agent),
            'printStats' => $printStats,
        ]);
    }

    public function update(SettingsRequest $request, UpdateSettingsAction $action, UpdatePrinterSettingsAction $printers): RedirectResponse
    {
        Gate::authorize('update', $this->company());
        Gate::authorize('update', $this->branch());
        $action->execute($this->company(), $this->branch(), $request->validated());
        $printers->execute($this->company(), $this->branch(), $request->user(), $request->validated('printers'));

        return back()->with('success', 'Configuración actualizada correctamente.');
    }

    public function updateChargeMode(ChargeModeRequest $request): RedirectResponse
    {
        Gate::authorize('update', $this->company());
        $this->company()->update(['table_charge_mode' => $request->validated('table_charge_mode')]);

        return back()->with('success', 'Modo de cobro actualizado para nuevas mesas.');
    }

    public function printTest(string $purpose, PrintTestPageAction $action): RedirectResponse
    {
        Gate::authorize('update', $this->company());
        Gate::authorize('update', $this->branch());
        $purpose = PrinterPurpose::tryFrom($purpose);
        abort_unless($purpose, 404);
        $setting = PrinterSetting::query()->forCompany($this->company())
            ->where('branch_id', $this->branch()->getKey())->where('purpose', $purpose->value)->firstOrFail();
        $attempt = $action->execute($setting, request()->user());

        return $attempt->status === PrintAttemptStatus::Failed
            ? back()->with('warning', 'No se pudo poner la prueba en cola. Revisa la impresora configurada.')
            : back()->with('success', 'Página de prueba pendiente de impresión.');
    }
}
