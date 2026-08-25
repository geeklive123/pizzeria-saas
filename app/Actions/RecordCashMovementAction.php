<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class RecordCashMovementAction
{
    public function execute(
        CashSession $session,
        CashMovementType $type,
        int|string $amount,
        User $user,
        ?string $reason = null,
        ?object $reference = null,
        ?CashMovement $reversalOf = null,
        ?string $observation = null,
        ?User $authorizedBy = null,
        ?string $idempotencyKey = null,
    ): CashMovement {
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        if ($amount->isNegative() || ($amount->isZero() && $type !== CashMovementType::Opening)) {
            throw new DomainException('Cash movement amount is invalid.');
        }

        $balance = BigDecimal::zero();
        foreach ($session->movements()->get(['type', 'amount']) as $movement) {
            $balance = $balance->plus(BigDecimal::of($movement->amount)->multipliedBy($movement->type->direction()));
        }
        $balance = $balance->plus($amount->multipliedBy($type->direction()))->toScale(2, RoundingMode::HalfUp);

        return CashMovement::query()->create([
            'company_id' => $session->company_id,
            'branch_id' => $session->branch_id,
            'cash_session_id' => $session->id,
            'type' => $type,
            'amount' => (string) $amount,
            'resulting_balance_amount' => (string) $balance,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference && method_exists($reference, 'getKey') ? $reference->getKey() : null,
            'reason' => $reason,
            'observation' => $observation,
            'created_by' => $user->id,
            'authorized_by' => $authorizedBy?->id,
            'occurred_at' => now(),
            'reversal_of_id' => $reversalOf?->id,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
