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
use Illuminate\Support\Collection;

class ReprintPaidOrderKitchenAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ThermalPrintingService $printing,
    ) {}

    /** @return Collection<int, PrintAttempt> */
    public function execute(Order $order, User $user): Collection
    {
        $order->loadMissing('company');
        $permission = $user->canForCompany(Permission::ManageOrders, $order->company_id)
            ? Permission::ManageOrders
            : Permission::ManageKitchen;
        $this->access->ensure($user, $order->company, $permission);

        if ($order->status !== OrderStatus::Paid) {
            throw new DomainException('Solo se pueden reimprimir comandas de pedidos pagados.');
        }

        $dispatches = $order->kitchenDispatches()->oldest('sequence_number')->get();

        if ($dispatches->isEmpty()) {
            return collect([$this->printing->kitchenOrder($order, $user)]);
        }

        return $dispatches->map(
            fn ($dispatch) => $this->printing->kitchen($dispatch, $user, true),
        );
    }
}
