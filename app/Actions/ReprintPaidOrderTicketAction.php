<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\ThermalPrintingService;
use DomainException;

class ReprintPaidOrderTicketAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ThermalPrintingService $printing,
    ) {}

    public function execute(Order $order, User $user): PrintAttempt
    {
        $order->loadMissing('company');
        $this->access->ensure($user, $order->company, Permission::CreatePayments);

        if ($order->status !== OrderStatus::Paid) {
            throw new DomainException('Solo se pueden reimprimir tickets de pedidos pagados.');
        }

        return $this->printing->ticket($order, $user, true);
    }
}
