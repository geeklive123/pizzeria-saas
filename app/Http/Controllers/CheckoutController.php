<?php

namespace App\Http\Controllers;

use App\Actions\MarkOrderReadyForPaymentAction;
use App\Actions\PrintOrderTicketAction;
use App\Actions\RegisterMixedPaymentAction;
use App\Actions\RegisterPaymentAction;
use App\Actions\ReversePaymentAction;
use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Enums\TableChargeMode;
use App\Exceptions\FinancialVersionConflict;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\PrepareCheckoutRequest;
use App\Http\Requests\ReversePaymentRequest;
use App\Models\DynamicQrIntent;
use App\Models\FinancialPaymentAttempt;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CurrentCashSessionService;
use App\Services\FinancialPaymentCoordinator;
use App\Services\OrderPaymentService;
use App\Services\ThermalPrintingService;
use App\Support\UiFormatter;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function financialAttempt(string $order, OrderPaymentService $payments): JsonResponse
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);
        $data = request()->validate(['key' => ['nullable', 'string', 'max:64'], 'dispatch' => ['nullable', 'ulid']]);
        $query = FinancialPaymentAttempt::query()->forCompany($this->company())
            ->where('branch_id', $this->branch()->id)->where('order_id', $order->id);
        if (! empty($data['key'])) {
            $query->where(fn ($query) => $query->whereNotNull('active_order_id')->orWhere(fn ($query) => $query
                ->where('idempotency_key', $data['key'])->where('user_id', request()->user()->id)));
        } else {
            $query->whereNotNull('active_order_id');
        }
        $attempt = $query->orderByRaw('active_order_id IS NULL')->orderByDesc('id')->first();
        $dispatch = $this->dispatch($order, $data['dispatch'] ?? null);
        $qrBlocked = DynamicQrIntent::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)
            ->where('order_id', $order->id)->where(fn ($query) => $query->whereNotNull('active_order_id')
            ->orWhere('status', DynamicQrStatus::ReconciliationRequired))->exists();

        return response()->json(['attempt' => $attempt ? $this->attemptPayload($attempt, $order) : null, 'financial_version' => $order->financial_version,
            'balance' => $dispatch ? $payments->dispatchBalance($dispatch) : $payments->balance($order), 'qr_blocked' => $qrBlocked])->header('Cache-Control', 'no-store');
    }

    public function reconcileFinancialAttempt(string $order, string $attempt, FinancialPaymentCoordinator $coordinator): JsonResponse
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);
        $attempt = FinancialPaymentAttempt::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)
            ->where('order_id', $order->id)->where('ulid', $attempt)->firstOrFail();
        $attempt = $coordinator->reconcile($attempt, request()->user());

        return response()->json(['attempt' => $this->attemptPayload($attempt, $order), 'financial_version' => $order->fresh()->financial_version])->header('Cache-Control', 'no-store');
    }

    private function attemptPayload(FinancialPaymentAttempt $attempt, Order $order): array
    {
        return [
            'ulid' => $attempt->ulid, 'status' => $attempt->status, 'method' => $attempt->method,
            'amount' => $attempt->amount, 'payment_ulid' => $attempt->payment_id ? Payment::query()->whereKey($attempt->payment_id)->value('ulid') : null,
            'owned_by_current_user' => $attempt->user_id === request()->user()->id,
            'idempotency_key' => $attempt->user_id === request()->user()->id ? $attempt->idempotency_key : null,
            'received_amount' => $attempt->user_id === request()->user()->id ? $attempt->received_amount : null,
            'dispatch_ulid' => $attempt->kitchen_dispatch_id ? KitchenDispatch::query()->whereKey($attempt->kitchen_dispatch_id)->value('ulid') : null,
            'reconcile_url' => route('orders.financial-attempt.reconcile', [$order->ulid, $attempt->ulid]),
            'active' => $attempt->active_order_id !== null,
            'message' => $attempt->active_order_id ? FinancialPaymentCoordinator::UNCERTAIN : null,
        ];
    }

    public function show(string $order, OrderPaymentService $payments, CurrentCashSessionService $cashSessions): View
    {
        $order = $this->order($order)->load(['restaurantTable', 'items.productVariant.product', 'payments.receivedBy', 'printAttempts']);
        Gate::authorize('view', $order);
        $dispatch = $this->dispatch($order, request('dispatch'));
        $dispatch?->load(['items.orderItem.productVariant.product', 'items.orderItem.sections', 'payments.receivedBy', 'printAttempts']);
        if ($dispatch) {
            $order->forceFill(['subtotal' => $dispatch->gross_subtotal, 'pizza_base_subtotal' => $dispatch->pizza_base_subtotal, 'extras_subtotal' => $dispatch->extras_subtotal, 'other_subtotal' => $dispatch->other_subtotal, 'discount_percentage' => $dispatch->discount_percentage, 'discount_total' => $dispatch->discount_total, 'total' => $dispatch->total]);
            $order->setRelation('payments', $dispatch->payments);
        }
        $session = $cashSessions->forUser($this->company(), $this->branch(), request()->user());
        $session?->load('cashRegister');
        $paid = $dispatch ? $payments->dispatchPaid($dispatch) : $payments->paid($order);
        $balance = $dispatch ? $payments->dispatchBalance($dispatch) : $payments->balance($order);
        $formatter = UiFormatter::class;
        $paymentClass = Payment::class;
        $idempotencyCash = (string) Str::ulid();
        $idempotencyQr = (string) Str::ulid();
        $ticketAttempts = $dispatch ? $dispatch->printAttempts : $order->printAttempts;
        $hasPrintedTicket = $ticketAttempts
            ->where('purpose', PrinterPurpose::CustomerTicket)
            ->filter(fn ($attempt): bool => $attempt->status->isPrinted())
            ->isNotEmpty();
        $lastTicketAttempt = $ticketAttempts
            ->where('purpose', PrinterPurpose::CustomerTicket)
            ->sortByDesc('attempted_at')->first();

        return view('orders.checkout', compact('order', 'dispatch', 'session', 'paid', 'balance', 'formatter', 'paymentClass', 'idempotencyCash', 'idempotencyQr', 'hasPrintedTicket', 'lastTicketAttempt'));
    }

    public function requestPayment(PrepareCheckoutRequest $request, string $order, MarkOrderReadyForPaymentAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            $action->execute($order, $request->user(), $request->validated('discount_percentage'));
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        if ($order->type === OrderType::DineIn && $order->charge_mode === TableChargeMode::AtEnd) {
            return redirect()->route('orders.show', $order->ulid)->with('success', 'Cuenta lista para cobrar.');
        }

        return redirect()->route('orders.checkout', $order->ulid)->with('success', 'Cuenta lista para cobrar.');
    }

    public function store(PaymentRequest $request, string $order, RegisterPaymentAction $action, RegisterMixedPaymentAction $mixedPayments, OrderPaymentService $payments, CurrentCashSessionService $cashSessions, ThermalPrintingService $printing): RedirectResponse|Response
    {
        $order = $this->order($order);
        $order->forceFill(['financial_version' => (int) $request->validated('financial_version')]);
        $dispatch = $this->dispatch($order, $request->validated('kitchen_dispatch'))
            ?? $order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment->value)->first();
        Gate::authorize('create', Payment::class);
        $session = $cashSessions->forUser($this->company(), $this->branch(), $request->user());
        if (! $session) {
            return back()->withInput()->withErrors(['payment' => 'Debes abrir tu propio turno de caja antes de cobrar.']);
        }
        try {
            if ($request->validated('method') === 'mixed') {
                $payment = $mixedPayments->execute(
                    $order,
                    $session,
                    $request->validated('cash_amount'),
                    $request->user(),
                    $request->validated('idempotency_key'),
                    $request->validated('received_amount'),
                    $request->validated('reference'),
                    $dispatch,
                );
            } else {
                $payment = $action->execute(
                    $order,
                    $session,
                    PaymentMethod::from($request->validated('method')),
                    $request->validated('amount'),
                    $request->user(),
                    $request->validated('idempotency_key'),
                    $request->validated('received_amount'),
                    $request->validated('reference'),
                    $dispatch,
                );
            }
        } catch (FinancialVersionConflict $exception) {
            $current = $this->order($order->ulid);
            $balance = $dispatch ? $payments->dispatchBalance($dispatch->fresh()) : $payments->balance($current);

            return response()->view('orders.financial-conflict', ['order' => $current, 'balance' => $balance], 409);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payment' => $exception->getMessage()]);
        }

        if ($dispatch) {
            $dispatch->refresh();
            if ($dispatch->status === KitchenDispatchStatus::Settled) {
                $printing->dispatchTicket($dispatch, $request->user(), false);
                if ($dispatch->order()->value('status') === OrderStatus::Paid->value) {
                    return redirect()->route('orders.index')->with('success', 'Pedido cobrado y ticket financiero enviado a impresión.');
                }

                return redirect()->route('orders.show', $order->ulid)->with('success', 'Tanda cobrada y liberada. La mesa continúa abierta.');
            }
        }
        if ($payment->order()->value('status') === OrderStatus::Paid->value) {
            $printing->ticket($order->refresh(), $request->user(), false);

            return redirect()->route($order->restaurant_table_id ? 'tables.index' : 'orders.index')
                ->with('success', 'Pago completado y cuenta cerrada correctamente.');
        }

        if (($dispatch ? $payments->dispatchBalance($dispatch) : $payments->balance($order)) === '0.00') {
            return back()->with('success', 'Pago completado.');
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

        return $attempt->status === PrintAttemptStatus::Failed
            ? back()->with('warning', 'No se pudo poner el ticket en cola. Revisa la configuración e inténtalo nuevamente.')
            : back()->with('success', 'Ticket pendiente de impresión.');
    }

    private function dispatch(Order $order, ?string $ulid): ?KitchenDispatch
    {
        if (blank($ulid)) {
            return null;
        }

        return KitchenDispatch::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('order_id', $order->id)->where('ulid', $ulid)->firstOrFail();
    }

    private function order(string $ulid): Order
    {
        return Order::query()->forCompany($this->company())->forBranch($this->branch())->where('ulid', $ulid)->firstOrFail();
    }
}
