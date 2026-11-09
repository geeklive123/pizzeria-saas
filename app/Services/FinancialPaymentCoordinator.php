<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashSession;
use App\Models\FinancialPaymentAttempt;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinancialPaymentCoordinator
{
    public const UNCERTAIN = 'El resultado del cobro aún no está confirmado. No vuelvas a cobrar hasta verificar el estado del pago.';

    public function __construct(private readonly CompanyAccessService $access, private readonly OrderFinancialVersion $versions) {}

    public function execute(Order $order, CashSession $session, User $user, string $key, string $method, string $amount, ?string $received, ?KitchenDispatch $dispatch, callable $operation): Payment
    {
        $this->access->ensure($user, $order->company, Permission::CreatePayments);
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('No se puede iniciar un cobro protegido dentro de otra transacción.');
        }

        $reserve = function () use ($order, $session, $user, $key, $method, $amount, $received, $dispatch): FinancialPaymentAttempt {
            $lockedSession = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedSession->company_id !== $lockedOrder->company_id || $lockedSession->branch_id !== $lockedOrder->branch_id || $lockedSession->opened_by !== $user->id) {
                throw new DomainException('El turno no corresponde al cobro.');
            }
            $existing = FinancialPaymentAttempt::query()->where('company_id', $order->company_id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->order_id !== $order->id || $existing->branch_id !== $order->branch_id || $existing->cash_session_id !== $session->id
                    || $existing->user_id !== $user->id || $existing->kitchen_dispatch_id !== $dispatch?->id || $existing->method !== $method
                    || ! BigDecimal::of($existing->amount)->isEqualTo($amount)
                    || (($existing->received_amount === null) !== ($received === null))
                    || ($received !== null && ! BigDecimal::of($existing->received_amount)->isEqualTo($received))) {
                    throw new DomainException('La identidad del cobro pertenece a otra operación.');
                }

                return $existing;
            }
            $this->versions->assertCurrent($lockedOrder, $order->financial_version);
            $this->assertNoActiveAttempt($lockedOrder);

            return FinancialPaymentAttempt::query()->create([
                'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                'active_order_id' => $order->id, 'kitchen_dispatch_id' => $dispatch?->id, 'cash_session_id' => $session->id,
                'user_id' => $user->id, 'idempotency_key' => $key, 'method' => $method,
                'amount' => $amount, 'received_amount' => $received, 'status' => 'pending', 'revision' => 0,
                'expected_financial_version' => $order->financial_version,
            ]);
        };
        try {
            $attempt = DB::transaction($reserve);
        } catch (Throwable $exception) {
            if (! (new LostConnectionDetector)->causedByLostConnection($exception)) {
                throw $exception;
            }
            DB::purge();
            try {
                // This repeats only the reservation; it never submits a payment.
                $attempt = DB::transaction($reserve);
            } catch (Throwable $recoveryException) {
                throw new DomainException(self::UNCERTAIN, previous: $recoveryException);
            }
        }

        try {
            return DB::transaction(function () use ($attempt, $session, $order, $operation): Payment {
                CashSession::query()->lockForUpdate()->findOrFail($session->id);
                Order::query()->lockForUpdate()->findOrFail($order->id);
                $locked = FinancialPaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($locked->status === 'confirmed' && $locked->payment_id) {
                    return Payment::query()->findOrFail($locked->payment_id);
                }
                if (! in_array($locked->status, ['pending', 'retryable'], true) || $locked->revision !== $attempt->revision) {
                    throw new DomainException(self::UNCERTAIN);
                }
                $payment = $operation($locked->revision);
                $locked->update(['status' => 'confirmed', 'active_order_id' => null, 'payment_id' => $payment->id]);

                return $payment;
            }, 3);
        } catch (Throwable $exception) {
            if ((new LostConnectionDetector)->causedByLostConnection($exception)) {
                DB::purge();
                try {
                    $recovered = $this->reconcile($attempt, $user);
                    if ($recovered->status === 'confirmed' && $recovered->payment_id) {
                        if ($dispatch) {
                            app(DeferredKitchenPrinting::class)->enqueue($dispatch->id);
                        }

                        return Payment::query()->findOrFail($recovered->payment_id);
                    }
                } catch (Throwable $recoveryException) {
                    throw new DomainException(self::UNCERTAIN, previous: $recoveryException);
                }
                throw new DomainException(self::UNCERTAIN, previous: $exception);
            }
            if ($exception instanceof DomainException && $exception->getMessage() !== self::UNCERTAIN) {
                DB::transaction(function () use ($attempt): void {
                    $locked = FinancialPaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                    if ($locked->revision === $attempt->revision && $locked->status === 'pending') {
                        $locked->update(['status' => 'failed', 'active_order_id' => null]);
                    }
                });
            }
            throw $exception;
        }
    }

    public function reconcile(FinancialPaymentAttempt $attempt, User $user): FinancialPaymentAttempt
    {
        $order = Order::query()->where('company_id', $attempt->company_id)->where('branch_id', $attempt->branch_id)->findOrFail($attempt->order_id);
        $this->access->ensure($user, $order->company, Permission::CreatePayments);

        return DB::transaction(function () use ($attempt): FinancialPaymentAttempt {
            CashSession::query()->lockForUpdate()->findOrFail($attempt->cash_session_id);
            Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $locked = FinancialPaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (! in_array($locked->method, ['cash', 'mixed'], true)) {
                throw new DomainException(self::UNCERTAIN);
            }
            $paymentKey = $locked->method === 'mixed' ? hash('sha256', $locked->idempotency_key.':cash') : $locked->idempotency_key;
            $payment = Payment::query()->where('company_id', $locked->company_id)->where('idempotency_key', $paymentKey)->first();
            if ($payment) {
                if ($payment->order_id !== $locked->order_id || $payment->branch_id !== $locked->branch_id
                    || $payment->cash_session_id !== $locked->cash_session_id || $payment->kitchen_dispatch_id !== $locked->kitchen_dispatch_id
                    || $payment->received_by !== $locked->user_id || ($locked->payment_id !== null && $locked->payment_id !== $payment->id)
                    || $payment->status !== PaymentStatus::Completed || $payment->method !== PaymentMethod::Cash
                    || $payment->received_amount === null
                    || ! BigDecimal::of($payment->amount)->isEqualTo($locked->amount)
                    || ! BigDecimal::of($payment->received_amount)->isEqualTo($locked->received_amount ?? $locked->amount)) {
                    throw new DomainException(self::UNCERTAIN);
                }
                $movements = DB::table('cash_movements')->where('company_id', $locked->company_id)->where('branch_id', $locked->branch_id)
                    ->where('cash_session_id', $locked->cash_session_id)->where('type', 'sale_cash')
                    ->where('reference_type', Payment::class)->where('reference_id', $payment->id)->get();
                if ($movements->count() !== 1 || ! BigDecimal::of($movements->first()->amount)->isEqualTo($payment->amount)) {
                    throw new DomainException(self::UNCERTAIN);
                }
                $locked->update(['status' => 'confirmed', 'active_order_id' => null, 'payment_id' => $payment->id]);
            } elseif ($locked->status === 'confirmed') {
                throw new DomainException(self::UNCERTAIN);
            } elseif ($locked->status === 'pending') {
                $locked->update(['status' => 'retryable', 'revision' => $locked->revision + 1]);
            }

            return $locked->refresh();
        });
    }

    public function assertNoActiveAttempt(Order $order, ?string $key = null): void
    {
        $active = FinancialPaymentAttempt::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('active_order_id', $order->id)->lockForUpdate()->first();
        if ($active && $active->idempotency_key !== $key) {
            throw new DomainException(self::UNCERTAIN);
        }
    }

    public function assertPaymentAllowed(Order $order, CashSession $session, User $user, string $key, PaymentMethod $method, string $amount, ?string $received, ?KitchenDispatch $dispatch, ?int $revision): void
    {
        $active = FinancialPaymentAttempt::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('active_order_id', $order->id)->lockForUpdate()->first();
        if (! $active) {
            throw new DomainException(self::UNCERTAIN);
        }
        if ($revision === null || $active->revision !== $revision
            || $active->order_id !== $order->id || $active->cash_session_id !== $session->id || $active->user_id !== $user->id
            || $active->expected_financial_version !== $order->financial_version
            || $active->kitchen_dispatch_id !== $dispatch?->id || $method !== PaymentMethod::Cash
            || ! in_array($active->status, ['pending', 'retryable'], true)
            || ! BigDecimal::of($active->amount)->isEqualTo($amount)
            || ! BigDecimal::of($active->received_amount ?? $active->amount)->isEqualTo($received ?? $amount)) {
            throw new DomainException(self::UNCERTAIN);
        }
        if ($active && $active->idempotency_key !== $key
            && ! ($active->method === 'mixed' && in_array($key, [hash('sha256', $active->idempotency_key.':cash'), hash('sha256', $active->idempotency_key.':qr')], true))) {
            throw new DomainException(self::UNCERTAIN);
        }
    }
}
