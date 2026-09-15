<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\ThermalPrintingService;
use DomainException;

class PrintProvisionalAccountAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ThermalPrintingService $printing,
    ) {}

    public function execute(Order $order, User $user): PrintAttempt
    {
        $order->loadMissing('company');
        $this->access->ensure($user, $order->company, Permission::ManageOrders);

        if ($order->type !== OrderType::DineIn || ! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true)) {
            throw new DomainException('Solo se puede imprimir la cuenta provisional de un pedido de mesa activo.');
        }

        return $this->printing->provisionalAccount($order, $user);
    }
}
