<?php

namespace App\Actions;

use App\Enums\DynamicQrStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\CashSession;
use App\Models\DynamicQrIntent;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\OnlineQrDisposition;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResolveOnlineQrDispositionAction
{
    /** @param array{disposition:string,reason:string,evidence:string,action_date:string,amount:string} $data */
    public function execute(OnlineOrderRequest $request, User $actor, array $data): void
    {
        $data = Validator::make($data, [
            'disposition' => ['required', 'in:accounting,refund,cancel'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'action_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'confirmed' => ['accepted'],
        ])->validate();
        if (mb_strlen(trim($data['reason'])) < 10 || mb_strlen(trim($data['evidence'])) < 10) {
            $this->reject('El motivo y la evidencia deben ser verificables.');
        }
        if (! Membership::query()->where('company_id', $request->company_id)->where('user_id', $actor->id)
            ->where('is_active', true)->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])->exists()) {
            throw new AuthorizationException;
        }
        $operation = DB::table('online_qr_operations')->where('online_order_request_id', $request->id)
            ->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)->first();
        if (! $operation || ! $operation->pos_order_id || ! $operation->dynamic_qr_intent_id) {
            $this->reject('No existe una operación QR vinculada.');
        }

        // Status 9 is labelled cancelled by the bank specification, but its
        // finality for in-flight payments has not been contractually verified.
        if ($data['disposition'] === 'cancel') {
            $this->reject('La cancelación permanece bloqueada hasta verificar con el banco que el QR no puede recibir pagos ni liquidaciones tardías.');
        }

        // Status 9 is labelled cancelled by the bank specification, but its
        // finality for in-flight payments has not been contractually verified.
        if ($data['disposition'] === 'cancel') {
            $this->reject('La cancelación permanece bloqueada hasta verificar con el banco que el QR no puede recibir pagos ni liquidaciones tardías.');
        }

        $bankLock = Cache::lock('baneco:intent:'.$operation->dynamic_qr_intent_id, 120);
        if (! $bankLock->get()) {
            $this->reject('Hay una consulta o resolución en curso. Inténtalo de nuevo.');
        }
        try {
            DB::transaction(function () use ($request, $actor, $data, $operation): void {
                // Match bank reconciliation: cash, order, then intent.
                $cash = CashSession::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                    ->lockForUpdate()->findOrFail($operation->cash_session_id);
                $order = Order::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                    ->lockForUpdate()->findOrFail($operation->pos_order_id);
                $intent = DynamicQrIntent::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                    ->lockForUpdate()->findOrFail($operation->dynamic_qr_intent_id);
                $operation = DB::table('online_qr_operations')->where('id', $operation->id)->lockForUpdate()->first();
                $request = OnlineOrderRequest::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                    ->lockForUpdate()->findOrFail($request->id);
                $membership = Membership::query()->where('company_id', $request->company_id)->where('user_id', $actor->id)
                    ->where('is_active', true)->lockForUpdate()->first();
                if (! $membership || ! in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)) {
                    throw new AuthorizationException;
                }
                if ((int) $operation->company_id !== (int) $request->company_id || (int) $operation->branch_id !== (int) $request->branch_id
                    || (int) $operation->online_order_request_id !== (int) $request->id || (int) $request->pos_order_id !== (int) $order->id
                    || (int) $operation->pos_order_id !== (int) $order->id || (int) $operation->dynamic_qr_intent_id !== (int) $intent->id
                    || (int) $operation->cash_session_id !== (int) $cash->id || (int) $intent->order_id !== (int) $order->id
                    || (int) $intent->cash_session_id !== (int) $cash->id || ! BigDecimal::of($request->total)->isEqualTo($intent->amount)
                    || ! BigDecimal::of($data['amount'])->isEqualTo($intent->amount)) {
                    $this->reject('Los vínculos o el importe requieren revisión.');
                }
                $existing = DB::table('online_qr_dispositions')->where('online_qr_operation_id', $operation->id)->first();
                if ($existing) {
                    if ($existing->disposition === $data['disposition'] && $existing->action_date === $data['action_date']
                        && BigDecimal::of($existing->amount)->isEqualTo($data['amount'])
                        && Crypt::decryptString($existing->reason) === trim($data['reason'])
                        && Crypt::decryptString($existing->evidence) === trim($data['evidence'])) {
                        return;
                    }
                    $this->reject('Ya existe una disposición final distinta.');
                }
                $legacy = DB::table('online_qr_resolutions')->where('dynamic_qr_intent_id', $intent->id)->first();
                if ($legacy && $legacy->resolution !== $data['disposition']) {
                    $this->reject('La resolución anterior requiere revisión antes de otra disposición.');
                }
                $inventoryIds = InventoryReservation::query()->where('order_id', $order->id)->orderBy('inventory_item_id')
                    ->pluck('inventory_item_id')->unique()->values();
                InventoryStock::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                    ->whereIn('inventory_item_id', $inventoryIds)->orderBy('inventory_item_id')->lockForUpdate()->get();
                $reservations = InventoryReservation::query()->where('order_id', $order->id)->orderBy('inventory_item_id')->orderBy('id')->lockForUpdate()->get();
                $result = $reservations->map(fn ($r) => ['id' => $r->id, 'item' => $r->inventory_item_id,
                    'quantity' => $r->quantity, 'status' => $r->status->value])->all();
                $orderResult = 'retained';
                if ($data['disposition'] === 'cancel') {
                    $bankCancelled = ($intent->status === DynamicQrStatus::Cancelled && $intent->issue_code === null)
                        || ($intent->status === DynamicQrStatus::ReconciliationRequired && $intent->issue_code === 'expiry_unconfirmed');
                    if (! $bankCancelled || $intent->bank_status !== '9' || ! $intent->bank_qr_id
                        || ! $intent->last_checked_at || $intent->payment_id || $order->payments()->exists()
                    || DynamicQrIntent::query()->where('order_id', $order->id)->whereKeyNot($intent->id)->exists()
                    || DB::table('financial_payment_attempts')->where('active_order_id', $order->id)->exists()
                        || ! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
                        $this->reject('No hay confirmación bancaria suficiente para cancelar sin riesgo de cobro.');
                    }
                    $items = $order->items()->orderBy('id')->lockForUpdate()->get();
                    if ($order->kitchenDispatches()->exists() || InventoryMovement::query()->where('reference_type', OrderItem::class)
                        ->whereIn('reference_id', $items->pluck('id'))->where('type', InventoryMovementType::OrderConsumption->value)->exists()) {
                        $this->reject('La orden tiene efectos operativos que requieren revisión.');
                    }
                    foreach ($reservations as $reservation) {
                        if ((int) $reservation->company_id !== (int) $request->company_id || (int) $reservation->branch_id !== (int) $request->branch_id
                        || ! $items->contains('id', $reservation->order_item_id) || $reservation->status !== InventoryReservationStatus::Reserved
                        || BigDecimal::of($reservation->quantity)->isLessThanOrEqualTo(0)) {
                            $this->reject('Las reservas requieren revisión.');
                        }
                        InventoryStock::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                            ->where('inventory_item_id', $reservation->inventory_item_id)->lockForUpdate()->first();
                        $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);
                        if ($locked->status !== InventoryReservationStatus::Reserved || (int) $locked->order_id !== (int) $order->id) {
                            $this->reject('La reserva cambió durante la revisión.');
                        }
                        $locked->forceFill(['status' => InventoryReservationStatus::Released, 'quantity' => '0.000', 'released_at' => now()])->save();
                    }
                    foreach ($items as $item) {
                        if (! in_array($item->status, [OrderItemStatus::Draft, OrderItemStatus::PendingPayment], true)) {
                            $this->reject('Las líneas requieren revisión.');
                        }
                        $item->forceFill(['status' => OrderItemStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                            'cancellation_reason' => trim($data['reason'])])->save();
                    }
                    $order->forceFill(['status' => OrderStatus::Cancelled, 'closed_at' => now(), 'cancelled_at' => now(),
                        'cancelled_by' => $actor->id, 'cancellation_reason' => trim($data['reason'])])->save();
                    $intent->forceFill(['status' => DynamicQrStatus::Cancelled, 'active_order_id' => null, 'issue_code' => null])->save();
                    $request->update(['status' => 'cancelled', 'resolved_at' => now(), 'resolved_by' => $actor->id]);
                    $orderResult = 'cancelled';
                } else {
                    $bank = $intent->reconciliation_data ?? [];
                    if (! in_array($data['disposition'], ['accounting', 'refund'], true) || $intent->status !== DynamicQrStatus::ReconciliationRequired
                    || $intent->bank_status !== '1' || ! $intent->bank_qr_id || ! $intent->last_checked_at || $intent->payment_id || $order->payments()->exists()
                        || ($bank['qrId'] ?? null) !== $intent->bank_qr_id || ($bank['currency'] ?? null) !== $intent->currency
                        || ! is_string($bank['amount'] ?? null) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $bank['amount'])
                        || ! BigDecimal::of($bank['amount'])->isEqualTo($intent->amount)) {
                        $this->reject('La evidencia de pago confirmado requiere revisión.');
                    }
                }
                OnlineQrDisposition::query()->create([
                    'company_id' => $request->company_id, 'branch_id' => $request->branch_id,
                    'online_order_request_id' => $request->id, 'order_id' => $order->id, 'online_qr_operation_id' => $operation->id,
                    'dynamic_qr_intent_id' => $intent->id, 'cash_session_id' => $cash->id, 'resolved_by' => $actor->id,
                    'disposition' => $data['disposition'], 'amount' => $intent->amount, 'action_date' => $data['action_date'],
                    'reason' => Crypt::encryptString(trim($data['reason'])), 'evidence' => Crypt::encryptString(trim($data['evidence'])),
                    'bank_qr_id' => $intent->bank_qr_id, 'bank_status' => $intent->bank_status,
                    'bank_checked_at' => $intent->last_checked_at,
                    'bank_evidence' => Crypt::encryptString(json_encode($intent->reconciliation_data ?? [], JSON_THROW_ON_ERROR)),
                    'inventory_result' => Crypt::encryptString(json_encode(['before' => $result, 'result' => $data['disposition'] === 'cancel' ? 'released' : 'unchanged'], JSON_THROW_ON_ERROR)),
                    'order_result' => $orderResult, 'resolved_at' => now(),
                ]);
            }, 3);
        } finally {
            $bankLock->release();
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['disposition' => $message]);
    }
}
