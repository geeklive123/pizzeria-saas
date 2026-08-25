<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashSession;
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
    ): Payment {
        $this->access->ensure($user, $order->company, Permission::CreatePayments);
        $existing = Payment::query()->forCompany($order->company)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->validateDuplicate($existing, $order, $method, $amount);
        }

        try {
            return DB::transaction(function () use ($order, $session, $method, $amount, $user, $idempotencyKey, $receivedAmount, $reference): Payment {
                $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
                $order = Order::query()->with('company')->lockForUpdate()->findOrFail($order->id);
                $duplicate = Payment::query()->forCompany($order->company_id)->where('idempotency_key', $idempotencyKey)->first();
                if ($duplicate) {
                    return $this->validateDuplicate($duplicate, $order, $method, $amount);
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
                $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
                $balance = BigDecimal::of($this->balances->balance($order));
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
                if (BigDecimal::of($this->balances->balance($order))->isZero()) {
                    $this->closeOrder->executeIfEligibleLocked($order);
                }

                return $payment->refresh();
            });
        } catch (QueryException $exception) {
            $duplicate = Payment::query()->forCompany($order->company_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($duplicate) {
                return $this->validateDuplicate($duplicate, $order, $method, $amount);
            }
            throw $exception;
        }
    }

    private function validateDuplicate(Payment $payment, Order $order, PaymentMethod $method, int|string $amount): Payment
    {
        if ((int) $payment->order_id !== (int) $order->id
            || $payment->method !== $method
            || ! BigDecimal::of($payment->amount)->isEqualTo(BigDecimal::of($amount))) {
            throw new DomainException('La operación de pago ya fue utilizada con datos diferentes.');
        }

        return $payment;
    }
}
