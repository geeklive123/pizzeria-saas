<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\Permission;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OwnerCashWithdrawalAction
{
    public function __construct(
        private readonly RecordCashMovementAction $movements,
        private readonly CashSessionSummaryService $summary,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(
        CashSession $session,
        int|string $amount,
        string $reason,
        ?string $observation,
        User $user,
        string $idempotencyKey,
    ): CashMovement {
        $this->access->ensure($user, $session->company, Permission::AuthorizeCashWithdrawals);
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        if ($amount->isLessThanOrEqualTo(0) || blank($reason)) {
            throw new DomainException('El retiro requiere un monto positivo y un motivo.');
        }

        $existing = $this->existing($session, $idempotencyKey);
        if ($existing) {
            return $this->validateDuplicate($existing, $session, $amount);
        }

        try {
            return DB::transaction(function () use ($session, $amount, $reason, $observation, $user, $idempotencyKey): CashMovement {
                $session = CashSession::query()->with('company')->lockForUpdate()->findOrFail($session->id);
                $duplicate = $this->existing($session, $idempotencyKey);
                if ($duplicate) {
                    return $this->validateDuplicate($duplicate, $session, $amount);
                }
                if ($session->status !== CashSessionStatus::Open) {
                    throw new DomainException('Un turno cerrado no acepta retiros ni movimientos.');
                }
                if ($amount->isGreaterThan(BigDecimal::of($this->summary->calculate($session)['expected_cash']))) {
                    throw new DomainException('El retiro no puede superar el efectivo físico esperado.');
                }

                return $this->movements->execute(
                    $session,
                    CashMovementType::OwnerWithdrawal,
                    (string) $amount,
                    $user,
                    $reason,
                    observation: $observation,
                    authorizedBy: $user,
                    idempotencyKey: $idempotencyKey,
                );
            });
        } catch (QueryException $exception) {
            $duplicate = $this->existing($session, $idempotencyKey);
            if ($duplicate) {
                return $this->validateDuplicate($duplicate, $session, $amount);
            }

            throw $exception;
        }
    }

    private function existing(CashSession $session, string $idempotencyKey): ?CashMovement
    {
        return CashMovement::query()->forCompany($session->company_id)
            ->where('idempotency_key', $idempotencyKey)->first();
    }

    private function validateDuplicate(CashMovement $movement, CashSession $session, BigDecimal $amount): CashMovement
    {
        if ((int) $movement->cash_session_id !== (int) $session->id
            || $movement->type !== CashMovementType::OwnerWithdrawal
            || ! BigDecimal::of($movement->amount)->isEqualTo($amount)) {
            throw new DomainException('La operación de retiro ya fue utilizada con datos diferentes.');
        }

        return $movement;
    }
}
