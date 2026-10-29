<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashSession;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\OrderPaymentService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RegisterPaymentAction
{
    public function __construct(
        private readonly RecordCashMovementAction $movements,
        private readonly ClosePaidOrderAction $closeOrder,
        private readonly OrderPaymentService $balances,
        private readonly CompanyAccessService $access,
        private readonly SettleKitchenDispatchAction $settleDispatch,
    ) {}

    public function execute(
        Order $order,
        CashSession $session,
        PaymentMethod $method,
        int|string $amount,
        User $user,
        string $idempotencyKey,
        ?string $receivedAmount = null,
        ?string $reference = null,
        ?KitchenDispatch $dispatch = null,
        ?DynamicQrIntent $bankIntent = null,
    ): Payment {
        $this->access->ensure($user, $order->company, Permission::CreatePayments);
        $existing = Payment::query()->forCompany($order->company)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->validateDuplicate($existing, $order, $method, $amount, $dispatch);
        }

        try {
            return DB::transaction(function () use ($order, $session, $method, $amount, $user, $idempotencyKey, $receivedAmount, $reference, $dispatch, $bankIntent): Payment {
                $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
                $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->id);
                $dispatch = $dispatch ? KitchenDispatch::query()->lockForUpdate()->findOrFail($dispatch->id) : null;
                $duplicate = Payment::query()->forCompany($order->company_id)->where('idempotency_key', $idempotencyKey)->first();
                if ($duplicate) {
                    return $this->validateDuplicate($duplicate, $order, $method, $amount, $dispatch);
                }
                $activeQr = DynamicQrIntent::query()->where('active_order_id', $order->id)->first();
                if ($activeQr && $activeQr->id !== $bankIntent?->id) {
                    throw new DomainException('Existe un QR bancario activo. Verifícalo o anúlalo antes de registrar otro pago.');
                }
                if (DynamicQrIntent::query()->where('order_id', $order->id)->where('status', DynamicQrStatus::ReconciliationRequired)->exists()) {
                    throw new DomainException('El pedido tiene una incidencia bancaria pendiente de conciliación.');
                }
                if ($bankIntent) {
                    $bankIntent = DynamicQrIntent::query()->lockForUpdate()->findOrFail($bankIntent->id);
                    $bankPayment = $bankIntent->reconciliation_data ?? [];
                    $bankAmount = $bankPayment['amount'] ?? null;
                    if ($method !== PaymentMethod::Qr || $bankIntent->bank_status !== '1'
                        || $bankIntent->active_order_id !== $order->id || $bankIntent->payment_id !== null
                        || ! is_string($bankIntent->bank_qr_id) || $bankIntent->bank_qr_id === ''
                        || ($bankPayment['qrId'] ?? null) !== $bankIntent->bank_qr_id
                        || ($bankPayment['currency'] ?? null) !== $bankIntent->currency
                        || ! is_string($bankAmount) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $bankAmount)
                        || ! BigDecimal::of($bankAmount)->isEqualTo($bankIntent->amount)
                        || $bankIntent->order_id !== $order->id || $bankIntent->cash_session_id !== $session->id
                        || $bankIntent->user_id !== $user->id || $bankIntent->kitchen_dispatch_id !== $dispatch?->id
                        || ! BigDecimal::of($bankIntent->amount)->isEqualTo($amount)) {
                        throw new DomainException('La confirmación bancaria no coincide con el contexto del pago.');
                    }
                } elseif ($method === PaymentMethod::Qr) {
                    throw new DomainException('El QR bancario solo se registra después de consultar al banco.');
                }
                if ($session->status !== CashSessionStatus::Open
                    || (int) $session->company_id !== (int) $order->company_id
                    || (int) $session->branch_id !== (int) $order->branch_id
                    || (int) $session->opened_by !== (int) $user->id) {
                    throw new DomainException('Debes cobrar desde tu propio turno de caja abierto.');
                }
                if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                    throw new DomainException('Este pedido no está disponible para cobrar.');
                }
                if ($dispatch && ((int) $dispatch->order_id !== (int) $order->id
                    || ! in_array($dispatch->status, [KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released], true))) {
                    throw new DomainException('La tanda no está disponible para cobrar.');
                }
                $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
                $balance = BigDecimal::of($dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order));
                if ($amount->isLessThanOrEqualTo(0) || $amount->isGreaterThan($balance)) {
                    throw new DomainException('El monto debe ser mayor que cero y no puede superar el saldo pendiente.');
                }

                $received = null;
                $change = null;
                if ($method === PaymentMethod::Cash) {
                    $received = BigDecimal::of($receivedAmount ?? (string) $amount)->toScale(2, RoundingMode::HalfUp);
                    if ($received->isLessThan($amount)) {
                        throw new DomainException('El efectivo recibido no puede ser menor que el monto cobrado.');
                    }
                    $change = $received->minus($amount)->toScale(2, RoundingMode::HalfUp);
                }

                $payment = Payment::query()->create([
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'kitchen_dispatch_id' => $dispatch?->id,
                    'cash_session_id' => $session->id,
                    'method' => $method,
                    'amount' => (string) $amount,
                    'reference' => $reference,
                    'received_amount' => $received ? (string) $received : null,
                    'change_amount' => $change ? (string) $change : null,
                    'paid_at' => now(),
                    'received_by' => $user->id,
                    'status' => PaymentStatus::Completed,
                    'idempotency_key' => $idempotencyKey,
                ]);
                if ($method === PaymentMethod::Cash) {
                    $this->movements->execute($session, CashMovementType::SaleCash, (string) $amount, $user, 'Cobro de venta', $payment);
                }
                if ($order->status === OrderStatus::Open) {
                    $order->forceFill(['status' => OrderStatus::ReadyForPayment])->save();
                }
                if ($dispatch && BigDecimal::of($this->balances->dispatchBalance($dispatch))->isZero()) {
                    $this->settleDispatch->execute($dispatch, $user);
                } elseif (! $dispatch && BigDecimal::of($this->balances->balance($order))->isZero()) {
                    $this->closeOrder->executeIfEligibleLocked($order);
                }

                return $payment->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $duplicate = Payment::query()->forCompany($order->company_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($duplicate) {
                return $this->validateDuplicate($duplicate, $order, $method, $amount, $dispatch);
            }
            throw $exception;
        }
    }

    private function validateDuplicate(
        Payment $payment,
        Order $order,
        PaymentMethod $method,
        int|string $amount,
        ?KitchenDispatch $dispatch,
    ): Payment {
        if ((int) $payment->order_id !== (int) $order->id
            || (int) $payment->kitchen_dispatch_id !== (int) $dispatch?->getKey()
            || $payment->method !== $method
            || ! BigDecimal::of($payment->amount)->isEqualTo(BigDecimal::of($amount))) {
            throw new DomainException('La operación de pago ya fue utilizada con datos diferentes.');
        }

        return $payment;
    }
}
