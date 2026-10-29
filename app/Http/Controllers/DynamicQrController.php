<?php

namespace App\Http\Controllers;

use App\Actions\DynamicQrAction;
use App\Enums\DynamicQrStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Services\BankQrImageInspection;
use App\Services\CurrentCashSessionService;
use App\Services\OrderPaymentService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DynamicQrController extends Controller
{
    public function __construct(private readonly OrderPaymentService $balances) {}

    public function store(Request $request, string $order, DynamicQrAction $action, CurrentCashSessionService $sessions): JsonResponse
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kitchen_dispatch' => ['nullable', 'ulid'],
            'cash_amount' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'received_amount' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'confirm_cash' => ['sometimes', 'accepted'],
        ]);
        if (BigDecimal::of($data['cash_amount'] ?? '0')->isPositive() && ! $request->boolean('confirm_cash')) {
            return response()->json(['message' => 'Confirma que recibiste el efectivo antes de registrarlo.'], 422);
        }
        if (BigDecimal::of($data['cash_amount'] ?? '0')->isPositive() && ! isset($data['received_amount'])) {
            return response()->json(['message' => 'Indica el efectivo recibido para calcular el cambio.'], 422);
        }
        $dispatch = ! empty($data['kitchen_dispatch']) ? $order->kitchenDispatches()->where('ulid', $data['kitchen_dispatch'])->firstOrFail() : null;
        $session = $sessions->forUser($this->company(), $this->branch(), $request->user());
        if (! $session) {
            return response()->json(['message' => 'Abre tu turno de caja antes de cobrar.'], 422);
        }
        try {
            return $this->payload($action->generate($order, $session, $request->user(), $data['idempotency_key'], $dispatch, $data['cash_amount'] ?? '0.00', $data['received_amount'] ?? null));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);
        $intent = DynamicQrIntent::query()->forCompany($order->company_id)->where('branch_id', $order->branch_id)
            ->where('order_id', $order->id)
            ->where(fn ($query) => $query->whereNotNull('active_order_id')->orWhere('status', DynamicQrStatus::ReconciliationRequired))
            ->orderByRaw('active_order_id IS NULL')->first();

        if ($intent) {
            return $this->payload($intent);
        }
        $requestDispatch = $request->validate(['dispatch' => ['nullable', 'ulid']])['dispatch'] ?? null;
        $dispatch = $requestDispatch ? $order->kitchenDispatches()->where('ulid', $requestDispatch)->firstOrFail() : null;

        return response()->json(['intent' => null, 'summary' => $this->summary($order, $dispatch)]);
    }

    public function check(string $order, string $intent, DynamicQrAction $action): JsonResponse
    {
        return $this->payload($action->check($this->intent($order, $intent)));
    }

    public function cancel(string $order, string $intent, DynamicQrAction $action): JsonResponse
    {
        return $this->payload($action->check($this->intent($order, $intent), true));
    }

    public function image(string $order, string $intent, BankQrImageInspection $images)
    {
        $intent = $this->intent($order, $intent);
        $bytes = $images->pngBytes($intent->qr_image);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function intent(string $order, string $intent): DynamicQrIntent
    {
        $order = $this->order($order);
        Gate::authorize('create', Payment::class);

        return DynamicQrIntent::query()->forCompany($order->company_id)->where('branch_id', $order->branch_id)
            ->where('order_id', $order->id)->where('ulid', $intent)->firstOrFail();
    }

    private function order(string $ulid): Order
    {
        return Order::query()->forCompany($this->company())->forBranch($this->branch())->where('ulid', $ulid)->firstOrFail();
    }

    private function payload(DynamicQrIntent $intent): JsonResponse
    {
        $message = match ($intent->status) {
            DynamicQrStatus::Paid => 'Pago confirmado',
            DynamicQrStatus::Cancelled => 'QR anulado. Puedes generar otro.',
            DynamicQrStatus::Failed => 'No se generó el QR. El efectivo registrado se conserva. Cancela este intento y vuelve a generar sin ingresar nuevamente el efectivo.',
            DynamicQrStatus::GenerationUnknown => 'Generación sin respuesta definitiva. No generes otro QR: requiere conciliación con el banco.',
            DynamicQrStatus::ReconciliationRequired => 'Requiere conciliación. No vuelvas a cobrar este importe. Contacta al responsable de caja.',
            DynamicQrStatus::Creating => 'Generando QR…',
            default => 'Esperando pago',
        };
        $order = $intent->order;
        $redirect = $order->status === OrderStatus::Paid
            ? route($order->restaurant_table_id ? 'tables.index' : 'orders.index')
            : route('orders.show', $order->ulid);

        $cash = Payment::query()->forCompany($intent->company_id)->where('order_id', $intent->order_id)
            ->where('kitchen_dispatch_id', $intent->kitchen_dispatch_id)->where('method', PaymentMethod::Cash)
            ->where('status', PaymentStatus::Completed)->where('idempotency_key', 'bqcash:'.hash('sha1', $intent->idempotency_key))->first();

        return response()->json(['summary' => $this->summary($order, $intent->kitchenDispatch), 'intent' => [
            'ulid' => $intent->ulid, 'status' => $intent->status->value, 'amount' => $intent->amount, 'currency' => $intent->currency,
            'commercial_reference' => $intent->commercial_reference, 'dispatch_ulid' => $intent->kitchenDispatch?->ulid,
            'bank_qr_id' => $intent->bank_qr_id,
            'cash_recorded' => $cash?->amount ?? '0.00', 'cash_received' => $cash?->received_amount, 'cash_change' => $cash?->change_amount,
            'image_url' => $intent->qr_image ? route('orders.dynamic-qr.image', [$order->ulid, $intent->ulid]) : null,
            'message' => $message, 'issue' => $intent->issue_code,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'check_url' => route('orders.dynamic-qr.check', [$order->ulid, $intent->ulid]),
            'cancel_url' => route('orders.dynamic-qr.cancel', [$order->ulid, $intent->ulid]),
            'redirect_url' => $redirect,
        ]])->header('Cache-Control', 'no-store');
    }

    private function summary(Order $order, ?KitchenDispatch $dispatch): array
    {
        $cash = BigDecimal::zero();
        $payments = $dispatch ? $dispatch->payments() : $order->payments();
        foreach ($payments->where('method', PaymentMethod::Cash)->where('status', PaymentStatus::Completed)->pluck('amount') as $amount) {
            $cash = $cash->plus((string) $amount);
        }

        return ['balance' => $dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order),
            'paid' => $dispatch ? $this->balances->dispatchPaid($dispatch) : $this->balances->paid($order),
            'cash_paid' => (string) $cash->toScale(2)];
    }
}
