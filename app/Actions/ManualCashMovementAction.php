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
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ManualCashMovementAction
{
    public function __construct(private readonly RecordCashMovementAction $movements, private readonly CashSessionSummaryService $summary, private readonly CompanyAccessService $access) {}

    public function execute(CashSession $session, CashMovementType $type, int|string $amount, string $reason, User $user): CashMovement
    {
        $this->access->ensure($user, $session->company, Permission::RegisterManualCashMovements);
        if ((int) $session->opened_by !== (int) $user->id
            && ! $user->canForCompany(Permission::AuthorizeCashWithdrawals, $session->company_id)) {
            throw new AuthorizationException('Solo la cajera del turno puede registrar movimientos operativos.');
        }
        if (! in_array($type, [CashMovementType::ManualIn, CashMovementType::ManualOut], true) || blank($reason)) {
            throw new DomainException('A valid manual movement and reason are required.');
        }

        return DB::transaction(function () use ($session, $type, $amount, $reason, $user): CashMovement {
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== CashSessionStatus::Open) {
                throw new DomainException('Cash session must be open.');
            }
            if ($type === CashMovementType::ManualOut
                && BigDecimal::of($amount)->isGreaterThan(BigDecimal::of($this->summary->calculate($session)['expected_cash']))) {
                throw new DomainException('Manual cash out cannot exceed expected physical cash.');
            }

            return $this->movements->execute($session, $type, $amount, $user, $reason);
        });
    }
}
