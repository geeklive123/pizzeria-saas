<?php

namespace App\Console\Commands;

use App\Enums\DynamicQrStatus;
use App\Jobs\ReconcileDynamicQr;
use App\Models\DynamicQrIntent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileDynamicQrCommand extends Command
{
    protected $signature = 'baneco:reconcile {--inline : Consultar sin worker de colas}';

    protected $description = 'Concilia QR y reintenta encolar tickets sin repetir cobros';

    public function handle(): int
    {
        DynamicQrIntent::query()->where('status', DynamicQrStatus::Creating)->where('created_at', '<', now()->subMinutes(3))
            ->eachById(function (DynamicQrIntent $intent): void {
                $lock = Cache::lock('baneco:intent:'.$intent->id, 120);
                if (! $lock->get()) {
                    return;
                }
                try {
                    $intent->refresh();
                    if ($intent->status === DynamicQrStatus::Creating && $intent->created_at->lt(now()->subMinutes(3))) {
                        $intent->update(['status' => DynamicQrStatus::GenerationUnknown, 'issue_code' => 'interrupted_generation']);
                        Log::warning('baneco_reconciliation_required', ['intent' => $intent->ulid, 'issue' => 'interrupted_generation']);
                    }
                } finally {
                    $lock->release();
                }
            });
        $dispatch = function (DynamicQrIntent $intent): void {
            if ($this->option('inline')) {
                ReconcileDynamicQr::dispatchSync($intent->id);
            } else {
                ReconcileDynamicQr::dispatch($intent->id);
            }
        };
        $paidToPrint = DynamicQrIntent::query()->where('status', DynamicQrStatus::Paid)->whereNull('ticket_enqueued_at')
            ->orderBy('id')->limit(20)->get();
        DynamicQrIntent::query()->where(function ($q): void {
            $q->where(fn ($q) => $q->whereNotNull('active_order_id')->whereNotNull('bank_qr_id')->where('status', '!=', DynamicQrStatus::Creating)
                ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinute())))
                // Watch confirmed cancellations for late bank postings; never auto-charge them.
                ->orWhere(fn ($q) => $q->where('status', DynamicQrStatus::Cancelled)->where('expires_at', '>', now()->subDays(7))
                    ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(15))));
        })->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->orderBy('id')->limit(50)->get()->each($dispatch);
        $paidToPrint->each($dispatch);
        $count = DynamicQrIntent::query()->whereIn('status', [DynamicQrStatus::GenerationUnknown, DynamicQrStatus::ReconciliationRequired])->count();
        $this->info('Conciliación solicitada. Operaciones que requieren revisión: '.$count);

        return self::SUCCESS;
    }
}
