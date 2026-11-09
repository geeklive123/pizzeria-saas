<?php

namespace App\Services;

use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderType;
use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Models\KitchenDispatch;
use App\Models\PrinterSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeferredKitchenPrinting
{
    public function request(KitchenDispatch $dispatch, User $user): void
    {
        if ($dispatch->order->type !== OrderType::Takeaway) {
            return;
        }
        if (! PrinterSetting::query()->where('company_id', $dispatch->company_id)->where('branch_id', $dispatch->branch_id)
            ->where('purpose', PrinterPurpose::Kitchen)->where('is_active', true)->where('auto_print', true)->exists()) {
            return;
        }
        $dispatch->forceFill(['auto_kitchen_print_requested_at' => now(), 'auto_kitchen_print_user_id' => $user->id])->save();
        DB::afterCommit(fn () => $this->enqueue($dispatch->id));
    }

    public function enqueue(int $dispatchId): void
    {
        try {
            DB::transaction(function () use ($dispatchId): void {
                $dispatch = KitchenDispatch::query()->lockForUpdate()->find($dispatchId);
                if (! $dispatch || $dispatch->status !== KitchenDispatchStatus::Settled || ! $dispatch->auto_kitchen_print_requested_at
                    || $dispatch->auto_kitchen_print_enqueued_at || ! $dispatch->auto_kitchen_print_user_id
                    || $dispatch->order->type !== OrderType::Takeaway) {
                    return;
                }
                $user = User::query()->findOrFail($dispatch->auto_kitchen_print_user_id);
                $printing = app(ThermalPrintingService::class);
                $attempt = $printing->kitchen($dispatch, $user, false);
                if ($attempt->status === PrintAttemptStatus::Failed) {
                    $attempt = $printing->retry($attempt);
                }
                if ($attempt->status !== PrintAttemptStatus::Failed) {
                    $dispatch->forceFill(['auto_kitchen_print_enqueued_at' => now()])->save();
                }
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('kitchen_print_enqueue_pending', ['dispatch_id' => $dispatchId, 'exception' => $exception::class]);
        }
    }
}
