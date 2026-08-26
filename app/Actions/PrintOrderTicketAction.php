<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\ThermalPrintingService;

class PrintOrderTicketAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ThermalPrintingService $printing,
    ) {}

    public function execute(Order $order, User $user): PrintAttempt
    {
        $order->loadMissing('company');
        $this->access->ensure($user, $order->company, Permission::CreatePayments);

        $lastAttempt = $order->printAttempts()->where('purpose', PrinterPurpose::CustomerTicket->value)
            ->latest('attempted_at')->first();
        if ($lastAttempt?->status === PrintAttemptStatus::Failed) {
            return $this->printing->retry($lastAttempt);
        }

        return $this->printing->ticket($order, $user, $lastAttempt !== null);
    }
}
