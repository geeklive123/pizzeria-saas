<?php

namespace App\Http\Controllers;

use App\Actions\TransferOrderPaymentsToCashSessionAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\TransferCashSessionRequest;
use App\Models\CashSession;
use App\Models\Order;
use App\Support\UiFormatter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CashSessionTransferController extends Controller
{
    public function create(string $order): View
    {
        $order = $this->order($order);
        Gate::authorize('transferPayments', $order);
        abort_unless($order->status === OrderStatus::Paid, 404);

        $order->load([
            'payments' => fn ($query) => $query
                ->where('status', PaymentStatus::Completed->value)
                ->with(['cashSession.cashRegister', 'cashSession.openedBy', 'receivedBy'])
                ->orderBy('id'),
        ]);
        $sessions = CashSession::query()
            ->forCompany($this->company())
            ->forBranch($this->branch())
            ->with(['cashRegister', 'openedBy'])
            ->latest('opened_at')
            ->get();

        return view('orders.transfer-cash-session', [
            'order' => $order,
            'sessions' => $sessions,
            'formatter' => UiFormatter::class,
        ]);
    }

    public function store(
        TransferCashSessionRequest $request,
        string $order,
        TransferOrderPaymentsToCashSessionAction $action,
    ): RedirectResponse {
        $order = $this->order($order);
        Gate::authorize('transferPayments', $order);
        $destination = CashSession::query()
            ->forCompany($this->company())
            ->forBranch($this->branch())
            ->where('ulid', $request->validated('destination_session'))
            ->firstOrFail();

        try {
            $action->execute(
                $this->company(),
                $order,
                $destination,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['transfer' => $exception->getMessage()]);
        }

        return redirect()->route('orders.show', $order->ulid)
            ->with('success', 'Los pagos fueron transferidos con trazabilidad y las cajas se recalcularon.');
    }

    private function order(string $ulid): Order
    {
        return Order::query()
            ->forCompany($this->company())
            ->forBranch($this->branch())
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
