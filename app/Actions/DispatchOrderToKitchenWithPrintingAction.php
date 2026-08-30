<?php

namespace App\Actions;

use App\Data\DispatchWithPrintResult;
use App\Enums\PrinterPurpose;
use App\Models\Order;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Services\ThermalPrintingService;

class DispatchOrderToKitchenWithPrintingAction
{
    public function __construct(
        private readonly DispatchOrderToKitchenAction $dispatch,
        private readonly ThermalPrintingService $printing,
    ) {}

    public function execute(Order $order, User $user, int|string|null $discountPercentage = null): DispatchWithPrintResult
    {
        $dispatch = $this->dispatch->execute($order, $user, $discountPercentage);
        if (! $dispatch) {
            return new DispatchWithPrintResult(null, null);
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
