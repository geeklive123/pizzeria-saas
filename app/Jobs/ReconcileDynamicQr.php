<?php

namespace App\Jobs;

use App\Actions\DynamicQrAction;
use App\Models\DynamicQrIntent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileDynamicQr implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public readonly int $intentId) {}

    public function uniqueId(): string
    {
        return (string) $this->intentId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(DynamicQrAction $action): void
    {
        $intent = DynamicQrIntent::query()->find($this->intentId);
        if ($intent) {
            $action->check($intent);
        }
    }
}
