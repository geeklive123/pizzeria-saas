<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\DynamicQrGenerationPhase;
use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\TableChargeMode;
use App\Models\BankQrSetting;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\BancoEconomicoGateway;
use App\Services\BankQrException;
use App\Services\CompanyAccessService;
use App\Services\OrderPaymentService;
use App\Services\ThermalPrintingService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DynamicQrAction
{
    public function __construct(
        private readonly BancoEconomicoGateway $gateway,
        private readonly RegisterPaymentAction $payments,
        private readonly OrderPaymentService $balances,
        private readonly CompanyAccessService $access,
        private readonly ClaimPreparedDynamicQrAction $claims,
    ) {}

    public function generate(Order $order, CashSession $session, User $user, string $key, ?KitchenDispatch $dispatch = null, string $cash = '0.00', ?string $received = null): DynamicQrIntent
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('No se puede generar un QR dentro de una transacción sin confirmar.');
        }
        if (! config('baneco.enabled')) {
            throw new DomainException('Banco Económico está desactivado para nuevos QR.');
        }
        $this->access->ensure($user, $order->company, Permission::CreatePayments);
        $created = false;
        $outerTransaction = DB::transactionLevel() === 0;
        try {
            $intent = DB::transaction(function () use ($order, $session, $user, $key, $dispatch, $cash, $received, &$created): DynamicQrIntent {
                $created = false;
                Company::query()->lockForUpdate()->findOrFail($order->company_id);
                $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                $dispatch = $dispatch ? KitchenDispatch::query()->lockForUpdate()->findOrFail($dispatch->id) : null;
                if ($session->status !== CashSessionStatus::Open || $session->company_id !== $order->company_id || $session->branch_id !== $order->branch_id || $session->opened_by !== $user->id) {
                    throw new DomainException('Debes usar tu propio turno abierto de esta sucursal.');
                }
                $existing = DynamicQrIntent::query()->forCompany($order->company_id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->order_id !== $order->id || $existing->kitchen_dispatch_id !== $dispatch?->id
                        || $existing->user_id !== $user->id || $existing->cash_session_id !== $session->id) {
                        throw new DomainException('La clave de operación pertenece a otro cobro.');
                    }
                    $existingCash = Payment::query()->forCompany($order->company_id)->where('idempotency_key', 'bqcash:'.hash('sha1', $key))->first();
                    $requestedCash = BigDecimal::of($cash)->toScale(2);
                    if (($existingCash === null && ! $requestedCash->isZero())
                        || ($existingCash && ($existingCash->status !== PaymentStatus::Completed
                            || $existingCash->received_amount === null
                            || ! BigDecimal::of($existingCash->amount)->isEqualTo($requestedCash)
                            || ! BigDecimal::of($existingCash->received_amount)->isEqualTo($received ?? $cash)))) {
                        throw new DomainException('La clave de operación ya fue utilizada con otro importe de efectivo.');
                    }

                    return $existing;
                }
                $active = DynamicQrIntent::query()->where('active_order_id', $order->id)->first();
                if ($active) {
                    if ($active->kitchen_dispatch_id !== $dispatch?->id) {
                        throw new DomainException('Resuelve el QR activo de la otra tanda primero.');
                    }
                    if (BigDecimal::of($cash)->isPositive()) {
                        throw new DomainException('Ya existe un QR activo. No se registró el efectivo; verifica el cobro existente.');
                    }

                    return $active;
                }
                if (DynamicQrIntent::query()->where('order_id', $order->id)->whereIn('status', [DynamicQrStatus::GenerationUnknown, DynamicQrStatus::ReconciliationRequired])->exists()) {
                    throw new DomainException('Existe una incidencia bancaria pendiente de conciliación en este pedido.');
                }
                if (! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                    throw new DomainException('El pedido no está disponible para cobrar.');
                }
                if ($dispatch && ($dispatch->order_id !== $order->id || $dispatch->company_id !== $order->company_id || $dispatch->branch_id !== $order->branch_id || ! in_array($dispatch->status, [KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released], true))) {
                    throw new DomainException('La tanda no está disponible para cobrar.');
                }
                if ($dispatch && $order->type === OrderType::DineIn && $order->charge_mode === TableChargeMode::AtEnd) {
                    throw new DomainException('Esta mesa se cobra al final por el saldo completo de la cuenta.');
                }
                if (! $dispatch && ($order->charge_mode === TableChargeMode::PerBatch || $order->kitchenDispatches()->where('status', KitchenDispatchStatus::AwaitingPayment)->exists())) {
                    throw new DomainException('Selecciona la tanda pendiente antes de generar el QR.');
                }
                if (! $dispatch && $order->status !== OrderStatus::ReadyForPayment) {
                    throw new DomainException('Solicita el cierre de la cuenta antes de generar el QR.');
                }
                $setting = BankQrSetting::forOrder($order);
                if (! $setting || ! $setting->enabled) {
                    throw new DomainException('Falta configurar Banco Económico para esta empresa o sucursal.');
                }
                $balance = BigDecimal::of($dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order));
                $cash = BigDecimal::of($cash)->toScale(2);
                if ($cash->isNegative() || $cash->isGreaterThanOrEqualTo($balance)) {
                    throw new DomainException('El efectivo debe ser menor que el saldo para dejar un importe QR positivo.');
                }
                if ($cash->isPositive()) {
                    $this->payments->execute($order, $session, PaymentMethod::Cash, (string) $cash, $user, 'bqcash:'.hash('sha1', $key), $received, dispatch: $dispatch);
                }
                $created = true;

                return DynamicQrIntent::query()->create([
                    'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                    'active_order_id' => $order->id, 'kitchen_dispatch_id' => $dispatch?->id, 'cash_session_id' => $session->id,
                    'user_id' => $user->id, 'bank_qr_setting_id' => $setting->id, 'amount' => (string) $balance->minus($cash)->toScale(2),
                    'currency' => 'BOB', 'commercial_reference' => now()->format('ymdHis').random_int(1000, 9999),
                    'idempotency_key' => $key, 'status' => DynamicQrStatus::Creating,
                    'generation_phase' => DynamicQrGenerationPhase::Prepared->value,
                    'expires_at' => now()->timezone('America/La_Paz')->addDays($setting->due_days ?? 1)->endOfDay()->utc(),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            if (! $outerTransaction || ! (new LostConnectionDetector)->causedByLostConnection($exception)) {
                throw $exception;
            }

            // The network request is below this boundary. Do not replay the transaction or generate a QR here.
            DB::purge();
            try {
                $recovered = DB::transaction(function () use ($order, $session, $user, $key, $dispatch, $cash, $received): ?DynamicQrIntent {
                    CashSession::query()->lockForUpdate()->findOrFail($session->id);
                    Order::query()->lockForUpdate()->findOrFail($order->id);
                    $intent = DynamicQrIntent::query()->forCompany($order->company_id)->where('idempotency_key', $key)->lockForUpdate()->first();
                    if (! $intent || $intent->order_id !== $order->id || $intent->branch_id !== $order->branch_id
                        || $intent->cash_session_id !== $session->id || $intent->user_id !== $user->id
                        || $intent->kitchen_dispatch_id !== $dispatch?->id) {
                        return null;
                    }
                    $payment = Payment::query()->forCompany($order->company_id)->where('idempotency_key', 'bqcash:'.hash('sha1', $key))->first();
                    if (BigDecimal::of($cash)->isZero()) {
                        return $payment ? null : $intent;
                    }
                    if (! $payment || $payment->status !== PaymentStatus::Completed || $payment->method !== PaymentMethod::Cash
                        || $payment->order_id !== $order->id || $payment->branch_id !== $order->branch_id
                        || $payment->kitchen_dispatch_id !== $dispatch?->id || $payment->cash_session_id !== $session->id
                        || ! BigDecimal::of($payment->amount)->isEqualTo($cash)
                        || $payment->received_amount === null || ! BigDecimal::of($payment->received_amount)->isEqualTo($received ?? $cash)) {
                        return null;
                    }
                    $movements = CashMovement::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
                        ->where('cash_session_id', $session->id)->where('type', CashMovementType::SaleCash)
                        ->where('reference_type', Payment::class)->where('reference_id', $payment->id)->get();
                    if ($movements->count() !== 1 || ! BigDecimal::of($movements->first()->amount)->isEqualTo($cash)) {
                        return null;
                    }

                    return $intent;
                });
            } catch (Throwable $recoveryException) {
                Log::warning('baneco_commit_recovery_unavailable', ['order_id' => $order->id, 'company_id' => $order->company_id]);
                throw new DomainException('El resultado del cobro es incierto. Consulta el estado antes de volver a cobrar.', previous: $recoveryException);
            }
            if (! $recovered) {
                Log::warning('baneco_commit_recovery_unresolved', ['order_id' => $order->id, 'company_id' => $order->company_id]);
                throw new DomainException('El resultado del cobro es incierto. Consulta el estado antes de volver a cobrar.', previous: $exception);
            }
            Log::info('baneco_commit_recovered', ['intent' => $recovered->ulid, 'company_id' => $order->company_id]);

            return $recovered;
        }
        if (! $created) {
            return $intent;
        }
        $generationLock = Cache::lock('baneco:intent:'.$intent->id, 120);
        if (! $generationLock->get()) {
            return $intent->refresh();
        }
        try {
            $intent->refresh();
            if ($intent->status !== DynamicQrStatus::Creating) {
                return $intent;
            }
            // A failed or uncertain claim commit must never lead to an HTTP request.
            if (! $this->claims->execute($intent)) {
                return $intent->refresh();
            }
            // Claim has committed. Armed attempts are never automatically sent twice.
            try {
                $data = $this->gateway->generate($intent);
                $intent->update(['bank_qr_id' => $data['qrId'], 'generation_phase' => DynamicQrGenerationPhase::Generated->value]);
                $image = $this->gateway->image(is_string($data['qrImage'] ?? null) ? $data['qrImage'] : '');
                $intent->update(['qr_image' => $image, 'status' => DynamicQrStatus::Pending]);
            } catch (BankQrException $exception) {
                $uncertain = $exception->uncertain || $this->gateway->generationAttempted();
                $intent->update(['status' => $uncertain ? DynamicQrStatus::GenerationUnknown : DynamicQrStatus::Failed, 'issue_code' => $exception->issue,
                    'generation_phase' => $uncertain ? DynamicQrGenerationPhase::GenerationUnknown->value : DynamicQrGenerationPhase::ErrorConfirmed->value]);
                if ($uncertain) {
                    $this->alert($intent);
                }
            } catch (Throwable) {
                // Includes a duplicate bank ID or a persistence failure after a remote success.
                $intent->update(['status' => DynamicQrStatus::GenerationUnknown, 'issue_code' => 'generation_result_unknown', 'generation_phase' => DynamicQrGenerationPhase::GenerationUnknown->value]);
                $this->alert($intent);
            }
        } finally {
            $generationLock->release();
        }

        return $intent->refresh();
    }

    public function check(DynamicQrIntent $intent, bool $cancel = false): DynamicQrIntent
    {
        $lock = Cache::lock('baneco:intent:'.$intent->id, 120);
        if (! $lock->get()) {
            return $intent->refresh();
        }
        try {
            $intent->refresh();
            if ($intent->status === DynamicQrStatus::Creating) {
                if ($intent->created_at->gte(now()->subMinutes(3))) {
                    return $intent;
                }
                // A missing QR ID is not evidence that generation was never sent.
                // Keep the active obligation blocked and only query a known bank ID.
                $intent->update(['status' => DynamicQrStatus::GenerationUnknown, 'issue_code' => 'interrupted_generation']);
                $this->alert($intent);
                if (! $intent->bank_qr_id) {
                    return $intent;
                }
            }
            if ($intent->status === DynamicQrStatus::Paid) {
                $this->print($intent);

                return $intent->refresh();
            }
            if ($cancel && $intent->status === DynamicQrStatus::Failed && ! $intent->bank_qr_id) {
                $intent->update(['status' => DynamicQrStatus::Cancelled, 'active_order_id' => null]);

                return $intent->refresh();
            }
            if (! $intent->bank_qr_id || (! $cancel && $intent->last_checked_at && $intent->last_checked_at->gt(now()->subSeconds(config('baneco.poll_seconds'))))) {
                return $intent;
            }
            if ($cancel && ! Cache::add('baneco:cancel:'.$intent->id, true, config('baneco.poll_seconds'))) {
                return $intent;
            }
            $intent->update(['last_checked_at' => now()]);
            try {
                $data = $this->queryStatus($intent);
                $intent = $this->apply($intent, $data);
                if ($cancel && ! in_array($intent->status, [DynamicQrStatus::Paid, DynamicQrStatus::Cancelled, DynamicQrStatus::ReconciliationRequired], true)) {
                    $this->gateway->cancel($intent);
                    // Even responseCode=0 is followed by an independent status check.
                    $intent = $this->apply($intent, $this->queryStatus($intent));
                    if (! in_array($intent->status, [DynamicQrStatus::Paid, DynamicQrStatus::Cancelled], true)) {
                        $intent->update(['issue_code' => 'cancellation_unconfirmed']);
                    }
                }
            } catch (BankQrException $exception) {
                $intent->update(['issue_code' => $exception->issue]);
            } catch (Throwable) {
                $intent->refresh()->update(['issue_code' => 'status_check_failed']);
            }
            $this->print($intent);

            return $intent->refresh();
        } finally {
            $lock->release();
        }
    }

    private function apply(DynamicQrIntent $intent, array $data): DynamicQrIntent
    {
        return DB::transaction(function () use ($intent, $data): DynamicQrIntent {
            // Same lock order as RegisterPaymentAction and CloseCashSessionAction.
            $session = CashSession::query()->lockForUpdate()->findOrFail($intent->cash_session_id);
            $order = Order::query()->lockForUpdate()->findOrFail($intent->order_id);
            $dispatch = $intent->kitchen_dispatch_id ? KitchenDispatch::query()->lockForUpdate()->findOrFail($intent->kitchen_dispatch_id) : null;
            $intent = DynamicQrIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($intent->payment_id) {
                return $intent;
            }
            $status = (string) ($data['statusQrCode'] ?? $data['statusQRCode'] ?? 'unknown');
            $intent->bank_status = mb_substr($status, 0, 20);
            if ($status === '9' && $intent->status !== DynamicQrStatus::ReconciliationRequired) {
                $intent->fill(['status' => DynamicQrStatus::Cancelled, 'active_order_id' => null, 'issue_code' => null]);
            } elseif ($status === '1') {
                $payment = $data['payment'] ?? [];
                $payment = is_array($payment) ? $payment : [];
                if (array_is_list($payment)) {
                    $payment = count($payment) === 1 && is_array($payment[0]) ? $payment[0] : [];
                }
                $amount = $payment['amount'] ?? null;
                $valid = is_string($amount) && preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)
                    && BigDecimal::of($amount)->isEqualTo($intent->amount)
                    && ($payment['currency'] ?? null) === $intent->currency
                    && (string) ($payment['qrId'] ?? '') === $intent->bank_qr_id;
                $intent->reconciliation_data = array_intersect_key($payment, array_flip(['qrId', 'amount', 'currency', 'transactionId', 'paymentDate', 'paymentTime']));
                $reason = ! $valid ? 'bank_payment_mismatch' : null;
                $reason ??= $intent->expires_at->isPast() || $intent->status === DynamicQrStatus::Cancelled ? 'late_payment' : null;
                $reason ??= $session->status !== CashSessionStatus::Open ? 'cash_session_closed' : null;
                $reason ??= ! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true) ? 'order_unavailable' : null;
                $balance = $dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order);
                $reason ??= ! BigDecimal::of($balance)->isEqualTo($intent->amount) ? 'balance_changed' : null;
                if ($intent->status === DynamicQrStatus::ReconciliationRequired) {
                    $reason ??= $intent->issue_code ?? 'manual_reconciliation_required';
                }
                if (! $reason) {
                    try {
                        $intent->save();
                        $record = $this->payments->execute($order, $session, PaymentMethod::Qr, $intent->amount, $intent->user, 'baneco:'.$intent->ulid, reference: 'Banco Económico '.$intent->bank_qr_id, dispatch: $dispatch, bankIntent: $intent);
                        $intent->fill(['payment_id' => $record->id, 'status' => DynamicQrStatus::Paid, 'active_order_id' => null, 'confirmed_at' => now(), 'issue_code' => null]);
                    } catch (DomainException|AuthorizationException) {
                        $reason = 'payment_context_rejected';
                    }
                }
                if ($reason) {
                    $intent->fill(['status' => DynamicQrStatus::ReconciliationRequired, 'issue_code' => $reason]);
                    $this->alert($intent);
                }
            } elseif (! in_array($status, ['0', '1', '9'], true)) {
                $intent->fill(['status' => DynamicQrStatus::ReconciliationRequired, 'issue_code' => 'unknown_bank_status']);
                $this->alert($intent);
            } elseif ($intent->expires_at->isPast() && $intent->status !== DynamicQrStatus::Cancelled) {
                // Local expiry is not proof that the bank can no longer receive money.
                $intent->fill(['status' => DynamicQrStatus::ReconciliationRequired, 'issue_code' => 'expiry_unconfirmed']);
                $this->alert($intent);
            } elseif ($intent->status === DynamicQrStatus::Pending) {
                $intent->issue_code = null;
            }
            $intent->save();

            return $intent;
        }, 3);
    }

    public function print(DynamicQrIntent $intent): void
    {
        if ($intent->status !== DynamicQrStatus::Paid || $intent->ticket_enqueued_at) {
            return;
        }
        try {
            $printing = app(ThermalPrintingService::class);
            if ($intent->kitchen_dispatch_id) {
                $printing->dispatchTicket($intent->kitchenDispatch, $intent->user, false);
            } elseif ($intent->order->status === OrderStatus::Paid) {
                $printing->ticket($intent->order, $intent->user, false);
            } else {
                return;
            }
            $intent->update(['ticket_enqueued_at' => now()]);
        } catch (Throwable) {
            // Financial commit is already durable. Scheduler retries the same print key.
            Log::warning('baneco_ticket_pending', ['intent' => $intent->ulid]);
        }
    }

    private function alert(DynamicQrIntent $intent): void
    {
        Log::warning('baneco_reconciliation_required', ['intent' => $intent->ulid, 'company_id' => $intent->company_id, 'issue' => $intent->issue_code]);
    }

    private function queryStatus(DynamicQrIntent $intent): array
    {
        try {
            $data = $this->gateway->status($intent);
            $status = $data['statusQrCode'] ?? $data['statusQRCode'] ?? null;
            Log::info('baneco_status_check', ['intent' => $intent->ulid, 'company_id' => $intent->company_id,
                'result' => is_string($status) && in_array($status, ['0', '1', '9'], true) ? $status : 'unknown']);

            return $data;
        } catch (Throwable $exception) {
            Log::warning('baneco_status_check', ['intent' => $intent->ulid, 'company_id' => $intent->company_id,
                'result' => 'error', 'issue' => $exception instanceof BankQrException ? $exception->issue : 'unexpected_error']);
            throw $exception;
        }
    }
}
