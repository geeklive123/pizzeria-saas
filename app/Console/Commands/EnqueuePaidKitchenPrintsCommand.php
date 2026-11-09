<?php

namespace App\Console\Commands;

use App\Enums\OrderType;
use App\Models\KitchenDispatch;
use App\Services\DeferredKitchenPrinting;
use Illuminate\Console\Command;

class EnqueuePaidKitchenPrintsCommand extends Command
{
    protected $signature = 'kitchen:enqueue-paid';

    protected $description = 'Reintenta encolar comandas pagadas pendientes';

    public function handle(DeferredKitchenPrinting $printing): int
    {
        KitchenDispatch::query()->whereNotNull('auto_kitchen_print_requested_at')->whereNull('auto_kitchen_print_enqueued_at')
            ->whereHas('order', fn ($query) => $query->where('type', OrderType::Takeaway))
            ->eachById(fn (KitchenDispatch $dispatch) => $printing->enqueue($dispatch->id));

        return self::SUCCESS;
    }
}
