<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\DynamicQrGenerationPhase;
use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OnlineOrderActionContext;
use App\Services\OrderPaymentService;
use App\Services\PrepareOnlineQrOperation;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Claims evidence only. The caller must not send unless this call returns true. */
class ClaimPreparedDynamicQrAction
{
    public function __construct(private readonly OrderPaymentService $balances) {}

    public function executeOnline(DynamicQrIntent $candidate, OnlineOrderRequest $preparation): bool
    {
        if (DB::transactionLevel() !== 0 || ! app()->runningUnitTests()) {
            throw new DomainException('El reclamo online solo está disponible con el banco simulado.');
        }
        $preparation->loadMissing(['company', 'posOrder.branch']);

        return app(PrepareOnlineQrOperation::class)->withPendingOperation($preparation->company, $preparation->posOrder->branch, $preparation,
            function (OnlineOrderRequest $locked, CashSession $session, User $actor, object $operation) use ($candidate): bool {
                $order = Order::query()->whereKey($locked->pos_order_id)->lockForUpdate()->firstOrFail();
                $context = OnlineOrderActionContext::fromLinkedOperation($locked, $session, $actor, $operation, $order);
                $context->assertOrder($order, $actor);
                $intent = DynamicQrIntent::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($intent->generation_phase !== DynamicQrGenerationPhase::Prepared->value || $intent->status !== DynamicQrStatus::Creating) {
                    return false;
                }
                if ((int) $operation->dynamic_qr_intent_id !== (int) $intent->id || (int) $intent->order_id !== (int) $order->id
                    || (int) $intent->company_id !== (int) $locked->company_id || (int) $intent->branch_id !== (int) $locked->branch_id
                    || (int) $intent->cash_session_id !== (int) $session->id || (int) $intent->user_id !== (int) $actor->id
                    || (int) $intent->active_order_id !== (int) $order->id || $intent->kitchen_dispatch_id !== null
                    || $intent->bank_qr_id !== null || $intent->currency !== 'BOB' || $intent->expires_at->isPast()
                    || ! BigDecimal::of($this->balances->balance($order))->isEqualTo($intent->amount)
                    || ! BigDecimal::of($intent->amount)->isEqualTo($locked->total)) {
                    throw new DomainException('El QR online requiere revisión.');
                }

                return DynamicQrIntent::query()->whereKey($intent->id)->where('status', DynamicQrStatus::Creating->value)
                    ->where('generation_phase', DynamicQrGenerationPhase::Prepared->value)
                    ->update(['generation_phase' => DynamicQrGenerationPhase::SendArmed->value, 'expires_at' => DB::raw('expires_at')]) === 1;
            });
    }

    public function execute(DynamicQrIntent $candidate): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('No se puede autorizar el envío dentro de otra transacción.');
        }

        return DB::transaction(function () use ($candidate): bool {
            $session = CashSession::query()->lockForUpdate()->findOrFail($candidate->cash_session_id);
            $order = Order::query()->lockForUpdate()->findOrFail($candidate->order_id);
            $dispatch = $candidate->kitchen_dispatch_id ? KitchenDispatch::query()->lockForUpdate()->findOrFail($candidate->kitchen_dispatch_id) : null;
            $intent = DynamicQrIntent::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($intent->generation_phase !== DynamicQrGenerationPhase::Prepared->value || $intent->status !== DynamicQrStatus::Creating) {
                return false;
            }
            if ($intent->company_id !== $order->company_id || $intent->branch_id !== $order->branch_id || $intent->order_id !== $order->id
                || $intent->kitchen_dispatch_id !== $dispatch?->id
                || $intent->active_order_id !== $order->id || $session->company_id !== $order->company_id
                || $session->branch_id !== $order->branch_id || $session->status !== CashSessionStatus::Open
                || $session->opened_by !== $intent->user_id || ! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)
                || $intent->cash_session_id !== $session->id || $intent->bank_qr_id !== null || $intent->currency !== 'BOB'
                || $intent->expires_at->isPast() || ($dispatch && ($dispatch->order_id !== $order->id
                    || $dispatch->company_id !== $order->company_id || $dispatch->branch_id !== $order->branch_id
                    || ! in_array($dispatch->status, [KitchenDispatchStatus::AwaitingPayment, KitchenDispatchStatus::Released], true)))) {
                throw new DomainException('El contexto del QR preparado requiere revisión.');
            }
            $balance = $dispatch ? $this->balances->dispatchBalance($dispatch) : $this->balances->balance($order);
            if (! BigDecimal::of($balance)->isEqualTo($intent->amount) || ! BigDecimal::of($intent->amount)->isPositive()) {
                throw new DomainException('El saldo del QR preparado requiere revisión.');
            }
            $cash = Payment::query()->where('company_id', $intent->company_id)->where('idempotency_key', 'bqcash:'.hash('sha1', $intent->idempotency_key))->first();
            if ($cash) {
                $movements = CashMovement::query()->where('company_id', $intent->company_id)->where('branch_id', $intent->branch_id)
                    ->where('cash_session_id', $session->id)->where('type', CashMovementType::SaleCash)
                    ->where('reference_type', Payment::class)->where('reference_id', $cash->id)->get();
                if ($cash->status !== PaymentStatus::Completed || $cash->method !== PaymentMethod::Cash
                    || $cash->order_id !== $order->id || $cash->branch_id !== $intent->branch_id
                    || $cash->cash_session_id !== $session->id || $cash->kitchen_dispatch_id !== $intent->kitchen_dispatch_id
                    || $movements->count() !== 1 || ! BigDecimal::of($movements->first()->amount)->isEqualTo($cash->amount)) {
                    throw new DomainException('El efectivo del QR preparado requiere revisión.');
                }
            }

            return DynamicQrIntent::query()->whereKey($intent->id)->where('status', DynamicQrStatus::Creating->value)
                ->where('generation_phase', DynamicQrGenerationPhase::Prepared->value)
                ->update(['generation_phase' => DynamicQrGenerationPhase::SendArmed->value, 'expires_at' => DB::raw('expires_at')]) === 1;
        });
    }
}
