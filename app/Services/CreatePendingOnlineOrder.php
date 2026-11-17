<?php

namespace App\Services;

use App\Actions\ConvertOnlineOrderRequestAction;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreatePendingOnlineOrder
{
    public function __construct(private readonly PrepareOnlineQrOperation $operations,
        private readonly ConvertOnlineOrderRequestAction $convert,
        private readonly OnlineOrderReservationVerifier $reservations) {}

    public function execute(Company $company, Branch $branch, OnlineOrderRequest $preparation): Order
    {
        $this->operations->execute($company, $branch, $preparation);

        return $this->operations->withPendingOperation($company, $branch, $preparation->fresh(),
            function (OnlineOrderRequest $locked, CashSession $cash, User $actor, object $operation): Order {
                if ($locked->pos_order_id !== null) {
                    if ((int) $operation->pos_order_id !== (int) $locked->pos_order_id) {
                        throw new DomainException('El vínculo de la orden online requiere revisión.');
                    }

                    return Order::query()->where('company_id', $locked->company_id)->where('branch_id', $locked->branch_id)
                        ->whereKey($locked->pos_order_id)->firstOrFail();
                }
                if ($operation->pos_order_id !== null) {
                    throw new DomainException('El vínculo de la orden online requiere revisión.');
                }
                $context = OnlineOrderActionContext::fromLockedOperation($locked, $cash, $actor, $operation);
                $order = $this->convert->execute($locked, $actor, $context);
                if (DB::table('online_qr_operations')->where('id', $operation->id)->whereNull('pos_order_id')
                    ->update(['pos_order_id' => $order->id, 'updated_at' => now()]) !== 1) {
                    throw new DomainException('El vínculo de la orden online requiere revisión.');
                }
                $locked->update(['pos_order_id' => $order->id]);
                $this->reservations->verify($locked, $cash);

                return $order;
            });
    }
}
