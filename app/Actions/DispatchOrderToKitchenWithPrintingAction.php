<?php

namespace App\Actions;

use App\Data\DispatchWithPrintResult;
use App\Enums\OrderType;
use App\Enums\PrinterPurpose;
use App\Models\Order;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Services\DeferredKitchenPrinting;
use App\Services\ThermalPrintingService;
use Illuminate\Support\Facades\DB;

class DispatchOrderToKitchenWithPrintingAction
{
    public function __construct(
        private readonly DispatchOrderToKitchenAction $dispatch,
        private readonly ThermalPrintingService $printing,
        private readonly DeferredKitchenPrinting $deferred,
    ) {}

    public function execute(Order $order, User $user, int|string|null $discountPercentage = null): DispatchWithPrintResult
    {
        $dispatch = DB::transaction(function () use ($order, $user, $discountPercentage) {
            $dispatch = $this->dispatch->execute($order, $user, $discountPercentage, true);
            if ($dispatch && $dispatch->order->type === OrderType::Takeaway) {
                $this->deferred->request($dispatch, $user);
            }

            return $dispatch;
        });
        if (! $dispatch) {
            return new DispatchWithPrintResult(null, null);
        }

        if ($dispatch->order->type === OrderType::Takeaway) {
            return new DispatchWithPrintResult($dispatch->refresh(), $dispatch->printAttempts()->where('purpose', PrinterPurpose::Kitchen->value)->where('is_reprint', false)->first());
        }

        $autoPrint = PrinterSetting::query()
            ->where('company_id', $dispatch->company_id)
            ->where('branch_id', $dispatch->branch_id)
            ->where('purpose', PrinterPurpose::Kitchen->value)
            ->where('is_active', true)
            ->where('auto_print', true)
            ->exists();

        return new DispatchWithPrintResult(
            $dispatch,
            $autoPrint ? $this->printing->kitchen($dispatch, $user, false) : null,
        );
    }
}
