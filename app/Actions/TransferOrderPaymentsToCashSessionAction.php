<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\MembershipRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CashSessionTransfer;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferOrderPaymentsToCashSessionAction
{
    public function __construct(
        private readonly RecordCashMovementAction $movements,
        private readonly CashSessionSummaryService $summary,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        Company $company,
        Order $order,
        CashSession $destinationSession,
        User $authorizedBy,
        string $reason,
    ): CashSessionTransfer {
        $this->authorize($company, $authorizedBy);

        if ((int) $order->company_id !== (int) $company->id
            || (int) $destinationSession->company_id !== (int) $company->id) {
            throw new DomainException('El pedido y la sesión destino deben pertenecer a la empresa autorizada.');
        }
        if ((int) $destinationSession->branch_id !== (int) $order->branch_id) {
            throw new DomainException('La sesión destino debe pertenecer a la misma sucursal del pedido.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Indica el motivo de la transferencia.');
        }

        return DB::transaction(function () use ($company, $order, $destinationSession, $authorizedBy, $reason): CashSessionTransfer {
            $order = Order::query()->with('company')
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->status !== OrderStatus::Paid) {
                throw new DomainException('Solo se pueden transferir pedidos pagados.');
            }

            /** @var Collection<int, CashSession> $sessions */
            $sessions = CashSession::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $order->branch_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $destinationSession = $sessions->get($destinationSession->id);
            if (! $destinationSession) {
                throw new DomainException('La sesión destino debe pertenecer a la misma empresa y sucursal del pedido.');
            }

            $destinationCashier = User::query()->find($destinationSession->opened_by);
            $destinationMembership = $destinationCashier
                ? Membership::query()
                    ->where('company_id', $company->id)
                    ->where('user_id', $destinationCashier->id)
                    ->where('is_active', true)
                    ->first()
                : null;
            if (! $destinationCashier || ! $destinationMembership) {
                throw new DomainException('La sesión destino no tiene un titular válido para esta empresa.');
            }

            /** @var Collection<int, Payment> $payments */
            $payments = Payment::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $order->branch_id)
                ->where('order_id', $order->id)
                ->where('status', PaymentStatus::Completed->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                throw new DomainException('El pedido no tiene pagos completados.');
            }

            $paymentIds = $payments->pluck('id');
            /** @var Collection<int, CashMovement> $relatedMovements */
            $relatedMovements = CashMovement::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $order->branch_id)
                ->where('reference_type', Payment::class)
                ->whereIn('reference_id', $paymentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $changedPayments = $payments->filter(
                fn (Payment $payment): bool => (int) $payment->cash_session_id !== (int) $destinationSession->id
                    || (int) $payment->received_by !== (int) $destinationCashier->id,
            );
            if ($changedPayments->isEmpty()) {
                throw new DomainException('El pedido ya pertenece a esa sesión.');
            }

            $sourceSessionIds = $payments->pluck('cash_session_id')->unique()->sort()->values();
            $sourceCashierIds = $payments->pluck('received_by')->unique()->sort()->values();
            $compensatingMovementIds = collect();

            foreach ($changedPayments as $payment) {
                $sourceSessionId = (int) $payment->cash_session_id;

                if ($payment->method === PaymentMethod::Cash && $sourceSessionId !== (int) $destinationSession->id) {
                    $sourceSession = $sessions->get($sourceSessionId);
                    if (! $sourceSession) {
                        throw new DomainException('Un pago pertenece a una sesión de caja inválida para el pedido.');
                    }

                    $hasCurrentCashEntry = $relatedMovements->contains(
                        fn (CashMovement $movement): bool => (int) $movement->reference_id === (int) $payment->id
                            && (int) $movement->cash_session_id === $sourceSessionId
                            && in_array($movement->type, [CashMovementType::SaleCash, CashMovementType::AdministrativeTransferIn], true),
                    );
                    if (! $hasCurrentCashEntry) {
                        throw new DomainException('No se encontró el movimiento de caja vigente para un pago en efectivo.');
                    }

                    $out = $this->movements->execute(
                        $sourceSession,
                        CashMovementType::AdministrativeTransferOut,
                        $payment->amount,
                        $destinationCashier,
                        $reason,
                        $payment,
                        observation: 'Transferencia administrativa de '.$order->formattedOperationalNumber(),
                        authorizedBy: $authorizedBy,
                        idempotencyKey: 'cash-transfer-out-'.$payment->ulid.'-'.(string) Str::ulid(),
                    );
                    $in = $this->movements->execute(
                        $destinationSession,
                        CashMovementType::AdministrativeTransferIn,
                        $payment->amount,
                        $destinationCashier,
                        $reason,
                        $payment,
                        observation: 'Transferencia administrativa de '.$order->formattedOperationalNumber(),
                        authorizedBy: $authorizedBy,
                        idempotencyKey: 'cash-transfer-in-'.$payment->ulid.'-'.(string) Str::ulid(),
                    );
                    $compensatingMovementIds->push($out->id, $in->id);
                }

                $payment->forceFill([
                    'cash_session_id' => $destinationSession->id,
                    'received_by' => $destinationCashier->id,
                ])->save();
            }

            $affectedSessionIds = $sourceSessionIds->concat([$destinationSession->id])->unique()->sort();
            foreach ($affectedSessionIds as $sessionId) {
                $this->summary->persistDerivedSnapshot($sessions->get((int) $sessionId));
            }

            return CashSessionTransfer::query()->create([
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'destination_cash_session_id' => $destinationSession->id,
                'destination_cashier_id' => $destinationCashier->id,
                'authorized_by' => $authorizedBy->id,
                'reason' => $reason,
                'source_cash_session_ids' => $sourceSessionIds->all(),
                'source_cashier_ids' => $sourceCashierIds->all(),
                'payment_ids' => $changedPayments->pluck('id')->values()->all(),
                'source_cash_movement_ids' => $relatedMovements->pluck('id')->values()->all(),
                'compensating_cash_movement_ids' => $compensatingMovementIds->values()->all(),
                'created_at' => now(),
            ]);
        });
    }

    private function authorize(Company $company, User $authorizedBy): void
    {
        $this->access->ensure($authorizedBy, $company, Permission::TransferCashSessionOperations);
        $membership = $authorizedBy->membershipFor($company);

        if (! $membership || ! in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)) {
            throw new AuthorizationException('Solo Owner o Admin pueden transferir cobros entre sesiones.');
        }
    }
}
