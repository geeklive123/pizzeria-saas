<?php

namespace App\Actions;

use App\Enums\CashClosingBalanceStatus;
use App\Enums\CashSessionStatus;
use App\Enums\Permission;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CloseCashSessionAction
{
    public function __construct(private readonly CashSessionSummaryService $summary, private readonly CompanyAccessService $access) {}

    public function execute(CashSession $session, int|string $countedAmount, User $user, ?string $observation = null): CashSession
    {
        $this->access->ensure($user, $session->company, Permission::CloseCash);
        if ((int) $session->opened_by !== (int) $user->id
            && ! $user->canForCompany(Permission::AuthorizeCashWithdrawals, $session->company_id)) {
            throw new AuthorizationException('Solo la cajera del turno o un Owner/Admin puede cerrarlo.');
        }
        $counted = BigDecimal::of($countedAmount)->toScale(2, RoundingMode::HalfUp);
        if ($counted->isNegative()) {
            throw new DomainException('Counted cash cannot be negative.');
        }

        return DB::transaction(function () use ($session, $counted, $user, $observation): CashSession {
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status === CashSessionStatus::Closed) {
                return $session;
            }
            $summary = $this->summary->calculate($session);
            $expected = BigDecimal::of($summary['expected_cash']);
            $difference = $counted->minus($expected)->toScale(2, RoundingMode::HalfUp);
            if (! $difference->isZero() && blank($observation)) {
                throw new DomainException('Debes explicar el faltante o sobrante antes de cerrar el turno.');
            }
            $balanceStatus = $difference->isZero()
                ? CashClosingBalanceStatus::Balanced
                : ($difference->isNegative() ? CashClosingBalanceStatus::Short : CashClosingBalanceStatus::Over);
            $session->forceFill([
                'active_cash_register_id' => null,
                'expected_cash_amount' => (string) $expected,
                'counted_cash_amount' => (string) $counted,
                'difference_amount' => (string) $difference,
                'closing_balance_status' => $balanceStatus,
                'status' => CashSessionStatus::Closed,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_observation' => $observation,
            ])->save();

            return $session->refresh();
        });
    }
}
