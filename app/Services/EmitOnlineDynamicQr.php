<?php

namespace App\Services;

use App\Actions\ClaimPreparedDynamicQrAction;
use App\Actions\DynamicQrAction;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EmitOnlineDynamicQr
{
    public function __construct(private readonly CreatePendingOnlineOrder $orders,
        private readonly PrepareOnlineQrOperation $operations, private readonly DynamicQrAction $qr,
        private readonly ClaimPreparedDynamicQrAction $claims, private readonly BankQrEmitter $emitter) {}

    public function execute(Company $company, Branch $branch, OnlineOrderRequest $preparation): DynamicQrIntent
    {
        if (! app()->runningUnitTests() || DB::transactionLevel() !== 0) {
            throw new DomainException('La emisión online todavía está limitada al banco simulado.');
        }
        Http::allowStrayRequests([]);
        Http::preventStrayRequests();
        $this->orders->execute($company, $branch, $preparation);
        $intent = $this->operations->withPendingOperation($company, $branch, $preparation->fresh(),
            function (OnlineOrderRequest $locked, CashSession $cash, User $actor, object $operation): DynamicQrIntent {
                $order = Order::query()->whereKey($locked->pos_order_id)->lockForUpdate()->firstOrFail();
                $context = OnlineOrderActionContext::fromLinkedOperation($locked, $cash, $actor, $operation, $order);

                return $this->qr->prepareOnline($order, $cash, $actor, $operation, $context);
            });

        return $this->emitter->emit($intent, fn (DynamicQrIntent $candidate): bool => $this->claims->executeOnline($candidate, $preparation->fresh()));
    }
}
