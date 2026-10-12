<?php

namespace App\Actions;

use App\Enums\InternalConsumptionStatus;
use App\Enums\Permission;
use App\Enums\PrintAttemptStatus;
use App\Models\InternalConsumption;
use App\Models\InternalConsumptionCancellation;
use App\Models\PrintAttempt;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelInternalConsumptionAction
{
    public function __construct(private readonly ReverseInventoryMovementAction $reverse, private readonly CompanyAccessService $access) {}

    public function execute(
        InternalConsumption $consumption,
        User $user,
        string $reason,
        bool $confirmedNotPrepared,
    ): InternalConsumption {
        $this->access->ensure($user, $consumption->company, Permission::CancelInternalConsumptions);
        if (blank($reason)) {
            throw new DomainException('El motivo de anulación es obligatorio.');
        }
        if (! $confirmedNotPrepared) {
            throw new DomainException('Debes confirmar que la pizza no fue preparada y que sus ingredientes no fueron utilizados.');
        }

        return DB::transaction(function () use ($consumption, $user, $reason): InternalConsumption {
            $consumption = InternalConsumption::query()->with(['company', 'components.movement'])
                ->lockForUpdate()->findOrFail($consumption->getKey());
            if ($consumption->status !== InternalConsumptionStatus::Confirmed || $consumption->cancellation()->lockForUpdate()->exists()) {
                throw new DomainException('El consumo interno ya fue anulado.');
            }

            $attempts = PrintAttempt::query()
                ->where('internal_consumption_id', $consumption->getKey())
                ->where(function ($query): void {
                    $query->whereIn('status', [
                        PrintAttemptStatus::Pending->value,
                        PrintAttemptStatus::Claimed->value,
                    ])
                        ->orWhere(function ($failed): void {
                            $failed->where('status', PrintAttemptStatus::Failed->value)
                                ->whereNotNull('available_at');
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($attempts as $attempt) {
                $attempt->update([
                    'status' => PrintAttemptStatus::Failed,
                    'available_at' => null,
                    'error_message' => 'Invalidada porque el consumo interno fue anulado antes de su preparación.',
                ]);
            }

            foreach ($consumption->components->sortBy('inventory_item_id') as $component) {
                $this->reverse->execute(
                    $component->movement, $user, "Anulación {$consumption->code}: {$reason}",
                    requiredPermission: Permission::CancelInternalConsumptions,
                );
            }
            InternalConsumptionCancellation::query()->create([
                'company_id' => $consumption->company_id, 'branch_id' => $consumption->branch_id,
                'internal_consumption_id' => $consumption->getKey(), 'reason' => $reason,
                'cancelled_by' => $user->getKey(), 'cancelled_at' => now(),
            ]);
            $consumption->forceFill(['status' => InternalConsumptionStatus::Cancelled, 'cancelled_at' => now()])->save();

            return $consumption->refresh()->load('cancellation.cancelledBy');
        });
    }
}
