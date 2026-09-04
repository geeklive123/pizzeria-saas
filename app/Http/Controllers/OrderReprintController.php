<?php

namespace App\Http\Controllers;

use App\Actions\ReprintPaidOrderKitchenAction;
use App\Actions\ReprintPaidOrderTicketAction;
use App\Enums\PrintAttemptStatus;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Services\PrintAgentStatusService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class OrderReprintController extends Controller
{
    public function kitchen(string $order, ReprintPaidOrderKitchenAction $action, PrintAgentStatusService $agents): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('reprintKitchen', $order);

        try {
            $attempts = $action->execute($order, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['printing' => $exception->getMessage()]);
        }

        return $this->response($attempts, $agents);
    }

    public function ticket(string $order, ReprintPaidOrderTicketAction $action, PrintAgentStatusService $agents): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('reprintCustomerTicket', $order);

        try {
            $attempt = $action->execute($order, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['printing' => $exception->getMessage()]);
        }

        return $this->response(collect([$attempt]), $agents);
    }

    /** @param Collection<int, PrintAttempt> $attempts */
    private function response(Collection $attempts, PrintAgentStatusService $agents): RedirectResponse
    {
        if ($attempts->contains(fn ($attempt) => $attempt->status === PrintAttemptStatus::Failed)) {
            return back()->with('warning', 'No se pudo poner el ticket en cola. Revisa la configuración de impresión.');
        }

        $agent = $agents->current($this->company(), $this->branch());

        return $agents->isOnline($agent)
            ? back()->with('success', 'Ticket enviado nuevamente a impresión.')
            : back()->with('warning', 'Ticket en cola. Se imprimirá cuando el agente vuelva a conectarse.');
    }

    private function order(string $ulid): Order
    {
        return Order::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('ulid', $ulid)->firstOrFail();
    }
}
