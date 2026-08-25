<?php

namespace App\Http\Controllers;

use App\Actions\MarkOrderReadyForPaymentAction;
use App\Actions\PrintOrderTicketAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\ReversePaymentAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\ReversePaymentRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CurrentCashSessionService;
use App\Services\OrderPaymentService;
use App\Support\UiFormatter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function show(string $order, OrderPaymentService $payments, CurrentCashSessionService $cashSessions): View
    {
        $order = $this->order($order)->load(['restaurantTable', 'items.productVariant.product', 'payments.receivedBy', 'printAttempts']);
        Gate::authorize('view', $order);
        $session = $cashSessions->forUser($this->company(), $this->branch(), request()->user());
        $session?->load('cashRegister');
        $paid = $payments->paid($order);
        $balance = $payments->balance($order);
        $formatter = UiFormatter::class;
        $paymentClass = Payment::class;
        $idempotencyCash = (string) Str::ulid();
        $idempotencyQr = (string) Str::ulid();
        $hasPrintedTicket = $order->printAttempts
            ->where('purpose', PrinterPurpose::CustomerTicket)
            ->where('status', PrintAttemptStatus::Succeeded)
            ->isNotEmpty();

        return view('orders.checkout', compact('order', 'session', 'paid', 'balance', 'formatter', 'paymentClass', 'idempotencyCash', 'idempotencyQr', 'hasPrintedTicket'));
    }

    public function requestPayment(string $order, MarkOrderReadyForPaymentAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            $action->execute($order, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return redirect()->route('orders.checkout', $order->ulid)->with('success', 'Cuenta lista para cobrar.');
    }

    public function store(PaymentRequest $request, string $order, RegisterPaymentAction $action, OrderPaymentService $payments, CurrentCashSessionService $cashSessions): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);
        $session = $cashSessions->forUser($this->company(), $this->branch(), $request->user());
        if (! $session) {
            return back()->withInput()->withErrors(['payment' => 'Debes abrir tu propio turno de caja antes de cobrar.']);
        }
        try {
            $payment = $action->execute(
                $order,
                $session,
                PaymentMethod::from($request->validated('method')),
                $request->validated('amount'),
                $request->user(),
                $request->validated('idempotency_key'),
                $request->validated('received_amount'),
                $request->validated('reference'),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payment' => $exception->getMessage()]);
        }

        if ($payment->order()->value('status') === OrderStatus::Paid->value) {
            return redirect()->route($order->restaurant_table_id ? 'tables.index' : 'orders.index')
                ->with('success', 'Pago completado y cuenta cerrada correctamente.');
        }

        if ($payments->balance($order) === '0.00') {
            return back()->with('success', 'Pago completado. El pedido seguirá activo hasta que todos los productos estén servidos.');
        }

        return back()->with('success', 'Pago registrado correctamente. Aún existe saldo pendiente.');
    }

    public function reverse(ReversePaymentRequest $request, string $payment, ReversePaymentAction $action): RedirectResponse
    {
        $payment = Payment::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)
            ->where('ulid', $payment)->firstOrFail();
        Gate::authorize('reverse', $payment);
        try {
            $action->execute($payment, $request->validated('reason'), $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['payment' => $exception->getMessage()]);
        }

        return back()->with('success', 'Pago revertido con trazabilidad.');
    }

    public function printTicket(string $order, PrintOrderTicketAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);
        $attempt = $action->execute($order, request()->user());

        return $attempt->status === PrintAttemptStatus::Succeeded
            ? back()->with('success', 'Ticket enviado a impresión.')
            : back()->with('warning', 'No se pudo imprimir el ticket. Revisa la impresora configurada e inténtalo nuevamente.');
    }

    private function order(string $ulid): Order
    {
        return Order::query()->forCompany($this->company())->forBranch($this->branch())->where('ulid', $ulid)->firstOrFail();
    }
}
