<?php

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Enums\DynamicQrStatus;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Internal capability scoped to one locked preparation and its original cash session. */
final class OnlineOrderActionContext
{
    private ?int $orderId = null;

    private function __construct(private readonly int $requestId, private readonly int $companyId,
        private readonly int $branchId, private readonly int $cashId, private readonly int $actorId, private readonly ?int $confirmedIntentId = null) {}

    /** Constructed only from persisted, bank-confirmed evidence under the payment transaction. */
    public static function fromConfirmedIntent(DynamicQrIntent $intent, Order $order, CashSession $cash, User $actor): self
    {
        if (DB::transactionLevel() < 1 || ! app()->runningUnitTests() || ! config('storefront.online_dynamic_qr_enabled')) {
            throw new DomainException('El contexto de confirmación online requiere revisión.');
        }
        // Re-read in the same order as payment reconciliation; callers may hold stale models.
        $cash = CashSession::query()->whereKey($cash->id)->lockForUpdate()->first();
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
        $intent = DynamicQrIntent::query()->whereKey($intent->id)->lockForUpdate()->first();
        if (! $cash || ! $order || ! $intent || $cash->status !== CashSessionStatus::Open || (int) $cash->opened_by === (int) $actor->id
            || ! in_array($intent->status, [DynamicQrStatus::Creating, DynamicQrStatus::Pending, DynamicQrStatus::GenerationUnknown], true)
            || (int) $intent->active_order_id !== (int) $order->id || ! is_string($intent->bank_qr_id) || $intent->bank_qr_id === ''
            || $intent->bank_status !== '1' || $intent->payment_id !== null || $intent->currency !== 'BOB'
            || (int) $intent->order_id !== (int) $order->id || (int) $intent->cash_session_id !== (int) $cash->id
            || (int) $intent->user_id !== (int) $actor->id || $intent->expires_at->isPast()) {
            throw new DomainException('El contexto de confirmación online requiere revisión.');
        }
        $operation = DB::table('online_qr_operations')->where('dynamic_qr_intent_id', $intent->id)
            ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('pos_order_id', $order->id)->where('cash_session_id', $cash->id)
            ->where('original_cashier_id', $cash->opened_by)->where('system_user_id', $actor->id)
            ->where('status', 'pending_emission')->lockForUpdate()->first();
        if (! $operation || ! app(OnlineSystemIdentityService::class)->isNonInteractiveForBranch($actor, (int) $order->company_id, (int) $order->branch_id)) {
            throw new DomainException('La operación online no corresponde a la intención bancaria.');
        }
        $request = OnlineOrderRequest::query()->whereKey($operation->online_order_request_id)
            ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('pos_order_id', $order->id)->where('preparation_cash_session_id', $cash->id)
            ->where('fulfillment', 'delivery')->whereIn('status', ['payment_preparation', 'preparation_invalidated'])
            ->lockForUpdate()->first();
        if (! $request) {
            throw new DomainException('La solicitud online requiere revisión.');
        }
        $evidence = $intent->reconciliation_data ?? [];
        if (($evidence['qrId'] ?? null) !== $intent->bank_qr_id || ($evidence['currency'] ?? null) !== 'BOB'
            || ! is_string($evidence['amount'] ?? null) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $evidence['amount'])
            || ! BigDecimal::of($evidence['amount'])->isEqualTo($intent->amount)
            || ! BigDecimal::of($request->total)->isEqualTo($intent->amount)) {
            throw new DomainException('La evidencia bancaria no coincide con la solicitud online.');
        }
        app(OnlineOrderReservationVerifier::class)->verify($request, $cash);
        $context = new self($request->id, $request->company_id, $request->branch_id, $cash->id, $actor->id, $intent->id);
        $context->bind($order);

        return $context;
    }

    public function assertConfirmedPayment(Order $order, CashSession $cash, User $actor, DynamicQrIntent $intent): void
    {
        $this->assertConfirmedOrder($order, $actor);
        if ($this->confirmedIntentId === null || $this->confirmedIntentId !== (int) $intent->id
            || $this->cashId !== (int) $cash->id || $intent->bank_status !== '1') {
            throw new DomainException('La autorización no corresponde a este pago online.');
        }
    }

    public function assertConfirmedOrder(Order $order, User $actor): void
    {
        $this->assertOrder($order, $actor);
        if ($this->confirmedIntentId === null) {
            throw new DomainException('La orden todavía no tiene confirmación bancaria.');
        }
        $cash = CashSession::query()->whereKey($this->cashId)->where('company_id', $this->companyId)
            ->where('branch_id', $this->branchId)->where('status', CashSessionStatus::Open->value)->lockForUpdate()->first();
        $intent = DynamicQrIntent::query()->whereKey($this->confirmedIntentId)->where('company_id', $this->companyId)
            ->where('branch_id', $this->branchId)->where('order_id', $order->id)->where('cash_session_id', $this->cashId)
            ->where('user_id', $this->actorId)->lockForUpdate()->first();
        $evidence = $intent?->reconciliation_data ?? [];
        if (! $cash || ! $intent || (int) $cash->opened_by === $this->actorId
            || ! in_array($intent->status, [DynamicQrStatus::Creating, DynamicQrStatus::Pending, DynamicQrStatus::GenerationUnknown], true)
            || $intent->bank_status !== '1' || $intent->payment_id !== null || (int) $intent->active_order_id !== (int) $order->id
            || $intent->expires_at->isPast() || ! is_string($intent->bank_qr_id) || $intent->bank_qr_id === ''
            || ($evidence['qrId'] ?? null) !== $intent->bank_qr_id || ($evidence['currency'] ?? null) !== 'BOB'
            || ! is_string($evidence['amount'] ?? null) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $evidence['amount'])
            || ! BigDecimal::of($evidence['amount'])->isEqualTo($intent->amount)) {
            throw new DomainException('La confirmación bancaria online requiere una nueva revisión.');
        }
    }

    public static function fromLockedOperation(OnlineOrderRequest $request, CashSession $cash, User $actor, object $operation): self
    {
        if (! config('storefront.online_dynamic_qr_enabled') || DB::transactionLevel() < 1
            || $request->status !== 'payment_preparation' || $request->fulfillment !== 'delivery'
            || $request->pos_order_id !== null || $cash->status !== CashSessionStatus::Open
            || ! CashSession::query()->whereKey($cash->id)->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                ->where('opened_by', $cash->opened_by)->where('status', CashSessionStatus::Open->value)->lockForUpdate()->exists()
            || ! OnlineOrderRequest::query()->whereKey($request->id)->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                ->where('status', 'payment_preparation')->where('fulfillment', 'delivery')->where('preparation_cash_session_id', $cash->id)
                ->whereNull('pos_order_id')->lockForUpdate()->exists()
            || (int) $request->preparation_cash_session_id !== (int) $cash->id
            || (int) $operation->online_order_request_id !== (int) $request->id
            || (int) $operation->company_id !== (int) $request->company_id
            || (int) $operation->branch_id !== (int) $request->branch_id
            || (int) $operation->cash_session_id !== (int) $cash->id
            || (int) $operation->original_cashier_id !== (int) $cash->opened_by
            || (int) $cash->opened_by === (int) $actor->id
            || (int) $operation->system_user_id !== (int) $actor->id || $operation->status !== 'pending_emission'
            || ! DB::table('online_qr_operations')->where('id', $operation->id)->where('online_order_request_id', $request->id)
                ->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                ->where('cash_session_id', $cash->id)->where('system_user_id', $actor->id)
                ->where('original_cashier_id', $cash->opened_by)->whereNull('pos_order_id')
                ->where('status', 'pending_emission')->exists()
            || ! app(OnlineSystemIdentityService::class)->isNonInteractiveForBranch($actor, (int) $request->company_id, (int) $request->branch_id)) {
            throw new DomainException('El contexto interno de la orden requiere revisión.');
        }

        return new self($request->id, $request->company_id, $request->branch_id, $cash->id, $actor->id);
    }

    public function assertCreate(Company $company, Branch $branch, User $actor): void
    {
        if (DB::transactionLevel() < 1 || $this->orderId !== null || (int) $company->id !== $this->companyId
            || (int) $branch->id !== $this->branchId || (int) $actor->id !== $this->actorId
            || ! CashSession::query()->whereKey($this->cashId)->where('status', CashSessionStatus::Open->value)->exists()
            || ! OnlineOrderRequest::query()->whereKey($this->requestId)->whereNull('pos_order_id')->where('status', 'payment_preparation')->exists()) {
            throw new DomainException('La autorización interna para crear la orden no es válida.');
        }
    }

    /** Only usable while the original preparation and cash session are locked. */
    public static function fromLinkedOperation(OnlineOrderRequest $request, CashSession $cash, User $actor, object $operation, Order $order): self
    {
        if (! config('storefront.online_dynamic_qr_enabled') || DB::transactionLevel() < 1
            || $request->status !== 'payment_preparation' || $request->fulfillment !== 'delivery'
            || $cash->status !== CashSessionStatus::Open || (int) $cash->opened_by === (int) $actor->id
            || ! CashSession::query()->whereKey($cash->id)->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                ->where('opened_by', $cash->opened_by)->where('status', CashSessionStatus::Open->value)->lockForUpdate()->exists()
            || ! OnlineOrderRequest::query()->whereKey($request->id)->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                ->where('status', 'payment_preparation')->where('fulfillment', 'delivery')->where('preparation_cash_session_id', $cash->id)
                ->where('pos_order_id', $order->id)->lockForUpdate()->exists()
            || (int) $request->preparation_cash_session_id !== (int) $cash->id
            || (int) $request->pos_order_id !== (int) $order->id
            || (int) $order->created_by !== (int) $actor->id
            || ! DB::table('online_qr_operations')->where('id', $operation->id)
                ->where('online_order_request_id', $request->id)->where('company_id', $request->company_id)
                ->where('branch_id', $request->branch_id)->where('cash_session_id', $cash->id)
                ->where('original_cashier_id', $cash->opened_by)->where('system_user_id', $actor->id)
                ->where('pos_order_id', $order->id)->where('status', 'pending_emission')->exists()
            || ! app(OnlineSystemIdentityService::class)->isNonInteractiveForBranch($actor, (int) $request->company_id, (int) $request->branch_id)) {
            throw new DomainException('El contexto de cobro online requiere revisión.');
        }
        $context = new self($request->id, $request->company_id, $request->branch_id, $cash->id, $actor->id);
        $context->bind($order);

        return $context;
    }

    public function assertRequest(OnlineOrderRequest $request, User $actor): void
    {
        if (DB::transactionLevel() < 1 || (int) $request->id !== $this->requestId
            || (int) $request->company_id !== $this->companyId || (int) $request->branch_id !== $this->branchId
            || (int) $actor->id !== $this->actorId || $request->status !== 'payment_preparation') {
            throw new DomainException('La solicitud no corresponde a la autorización interna.');
        }
    }

    public function bind(Order $order): void
    {
        if ($this->orderId !== null || (int) $order->company_id !== $this->companyId
            || (int) $order->branch_id !== $this->branchId || (int) $order->created_by !== $this->actorId) {
            throw new DomainException('La orden no pertenece a la operación interna.');
        }
        $this->orderId = (int) $order->id;
    }

    public function assertOrder(Order $order, User $actor): void
    {
        if (DB::transactionLevel() < 1 || $this->orderId === null || $this->orderId !== (int) $order->id
            || (int) $order->company_id !== $this->companyId || (int) $order->branch_id !== $this->branchId
            || (int) $order->created_by !== $this->actorId || (int) $actor->id !== $this->actorId
            || ! app(OnlineSystemIdentityService::class)->isNonInteractiveForBranch($actor, $this->companyId, $this->branchId)) {
            throw new DomainException('La autorización interna no corresponde a esta orden.');
        }
    }
}
