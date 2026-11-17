<?php

namespace App\Actions;

use App\Enums\DynamicQrStatus;
use App\Enums\MembershipRole;
use App\Models\CashSession;
use App\Models\DynamicQrIntent;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OnlineOrderReservationVerifier;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfirmOnlineQrDeliveryAction
{
    public function __construct(private readonly OnlineOrderReservationVerifier $verifier, private readonly ConsumeInventoryReservationAction $consume) {}

    /** @param array<string, mixed> $data */
    public function execute(OnlineOrderRequest $request, User $actor, array $data): void
    {
        $data = Validator::make($data, [
            'action_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmed' => ['accepted'],
        ])->validate();
        if (mb_strlen(trim($data['reason'])) < 10 || mb_strlen(trim($data['evidence'])) < 10) {
            $this->reject('La entrega requiere motivo y evidencia verificables.');
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
        $lock = Cache::lock('baneco:intent:'.$operation->dynamic_qr_intent_id, 120);
        if (! $lock->get()) {
            $this->reject('Hay una consulta o resolución en curso. Inténtalo de nuevo.');
        }
        try {
            DB::transaction(function () use ($request, $actor, $data, $operation): void {
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
                if (! $operation || (int) $operation->company_id !== (int) $request->company_id || (int) $operation->branch_id !== (int) $request->branch_id
                    || (int) $operation->online_order_request_id !== (int) $request->id || (int) $operation->pos_order_id !== (int) $order->id
                    || (int) $request->pos_order_id !== (int) $order->id || (int) $operation->dynamic_qr_intent_id !== (int) $intent->id
                    || (int) $intent->order_id !== (int) $order->id || (int) $intent->cash_session_id !== (int) $cash->id) {
                    $this->reject('Los vínculos de la operación requieren revisión.');
                }
                if ($intent->status !== DynamicQrStatus::ReconciliationRequired || $intent->bank_status !== '1' || $intent->payment_id !== null) {
                    $this->reject('El estado bancario cambió y requiere una nueva revisión.');
                }
                if ($intent->status !== DynamicQrStatus::ReconciliationRequired || $intent->bank_status !== '1' || $intent->payment_id !== null) {
                    $this->reject('El estado bancario cambió y requiere una nueva revisión.');
                }
                $disposition = DB::table('online_qr_dispositions')->where('online_qr_operation_id', $operation->id)
                    ->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
                    ->where('order_id', $order->id)->where('dynamic_qr_intent_id', $intent->id)->lockForUpdate()->first();
                if (! $disposition || ! in_array($disposition->disposition, ['accounting', 'refund'], true)) {
                    $this->reject('Primero debe registrarse la resolución administrativa del pago.');
                }
                $bank = $intent->reconciliation_data ?? [];
                if (! $intent->bank_qr_id || ($bank['qrId'] ?? null) !== $intent->bank_qr_id
                    || ($bank['currency'] ?? null) !== $intent->currency || ! is_string($bank['amount'] ?? null)
                    || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $bank['amount'])
                    || ! BigDecimal::of($bank['amount'])->isEqualTo($intent->amount)
                    || ! BigDecimal::of($disposition->amount)->isEqualTo($intent->amount)) {
                    $this->reject('La evidencia bancaria requiere revisión.');
                }
                $existing = DB::table('online_qr_inventory_outcomes')->where('online_qr_disposition_id', $disposition->id)->first();
                if ($existing) {
                    if ($existing->outcome === 'delivered' && $existing->action_date === $data['action_date']
                        && Crypt::decryptString($existing->reason) === trim($data['reason'])
                        && Crypt::decryptString($existing->evidence) === trim($data['evidence'])) {
                        return;
                    }
                    $this->reject('El resultado de inventario ya está registrado.');
                }
                $this->verifier->verify($request, $cash);
                $items = $order->items()->orderBy('id')->lockForUpdate()->get();
                if ($order->kitchenDispatches()->exists() || InventoryMovement::query()->where('reference_type', OrderItem::class)
                    ->whereIn('reference_id', $items->pluck('id'))->where('type', 'order_consumption')->exists()) {
                    $this->reject('La orden ya tiene efectos operativos que requieren revisión.');
                }
                $before = $order->reservations()->orderBy('id')->get()->map(fn ($r) => [
                    'id' => $r->id, 'item' => $r->inventory_item_id, 'quantity' => $r->quantity,
                ])->all();
                foreach ($items as $item) {
                    $this->consume->execute($item, $actor);
                }
                DB::table('online_qr_inventory_outcomes')->insert([
                    'online_qr_disposition_id' => $disposition->id, 'resolved_by' => $actor->id,
                    'action_date' => $data['action_date'], 'outcome' => 'delivered',
                    'reason' => Crypt::encryptString(trim($data['reason'])), 'evidence' => Crypt::encryptString(trim($data['evidence'])),
                    'inventory_result' => Crypt::encryptString(json_encode($before, JSON_THROW_ON_ERROR)), 'resolved_at' => now(),
                ]);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['inventory_outcome' => $message]);
    }
}
