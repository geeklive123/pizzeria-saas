<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\Permission;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpenCashSessionAction
{
    public function __construct(private readonly RecordCashMovementAction $movements, private readonly CompanyAccessService $access) {}

    public function execute(CashRegister $register, int|string $openingAmount, User $user, ?string $notes = null): CashSession
    {
        $this->access->ensure($user, $register->company, Permission::OpenCash);
        $openingAmount = BigDecimal::of($openingAmount)->toScale(2, RoundingMode::HalfUp);
        if ($openingAmount->isNegative()) {
            throw new DomainException('Opening amount cannot be negative.');
        }

        return DB::transaction(function () use ($register, $openingAmount, $user, $notes): CashSession {
            $register = CashRegister::query()->with('company')->lockForUpdate()->findOrFail($register->id);
            if (! $register->is_active) {
                throw new DomainException('The cash register is inactive.');
            }
            $activeSession = CashSession::query()->where('active_cash_register_id', $register->id)
                ->with('openedBy:id,name')->first();
            if ($activeSession) {
                $openedAt = $activeSession->opened_at->setTimezone('America/La_Paz')->format('d/m/Y H:i');
                throw new DomainException("{$register->name} ya está ocupada por {$activeSession->openedBy->name} desde {$openedAt}.");
            }
            $previousSession = CashSession::query()->where('cash_register_id', $register->id)
                ->where('status', CashSessionStatus::Closed->value)->latest('closed_at')->first();
            $inherited = $previousSession?->counted_cash_amount;
            $openingDifference = $inherited === null
                ? BigDecimal::zero()
                : $openingAmount->minus($inherited);
            $session = CashSession::query()->create([
                'company_id' => $register->company_id,
                'branch_id' => $register->branch_id,
                'cash_register_id' => $register->id,
                'previous_cash_session_id' => $previousSession?->id,
                'active_cash_register_id' => $register->id,
                'opened_by' => $user->id,
                'opening_amount' => (string) $openingAmount,
                'inherited_cash_amount' => $inherited,
                'opening_difference_amount' => (string) $openingDifference->toScale(2, RoundingMode::HalfUp),
                'expected_cash_amount' => (string) $openingAmount,
                'status' => CashSessionStatus::Open,
                'opened_at' => now(),
                'notes' => $notes,
            ]);
            $this->movements->execute($session, CashMovementType::Opening, (string) $openingAmount, $user, 'Apertura de caja');

            return $session->refresh();
        });
    }
}
