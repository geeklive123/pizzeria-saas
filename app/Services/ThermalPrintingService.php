<?php

namespace App\Services;

use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Printing\Renderers\CustomerTicketRenderer;
use App\Printing\Renderers\KitchenCommandRenderer;
use App\Printing\Renderers\TestPageRenderer;
use App\Printing\ThermalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ThermalPrintingService
{
    public function __construct(
        private readonly KitchenCommandRenderer $kitchenRenderer,
        private readonly CustomerTicketRenderer $ticketRenderer,
        private readonly TestPageRenderer $testRenderer,
    ) {}

    public function kitchen(KitchenDispatch $dispatch, User $user, bool $reprint): PrintAttempt
    {
        $setting = $this->setting($dispatch->company_id, $dispatch->branch_id, PrinterPurpose::Kitchen);

        return $this->enqueue(
            $setting,
            $this->kitchenRenderer->render($dispatch),
            $user,
            $reprint,
            $dispatch,
            null,
            $reprint ? 'kitchen_dispatch:'.$dispatch->ulid.':reprint:'.Str::ulid() : 'kitchen_dispatch:'.$dispatch->ulid.':original',
        );
    }

    public function ticket(Order $order, User $user, bool $reprint): PrintAttempt
    {
        $setting = $this->setting($order->company_id, $order->branch_id, PrinterPurpose::CustomerTicket);

        return $this->enqueue(
            $setting,
            $this->ticketRenderer->render($order, $user),
            $user,
            $reprint,
            null,
            $order,
            $reprint ? 'customer_ticket:'.$order->ulid.':reprint:'.Str::ulid() : 'customer_ticket:'.$order->ulid.':original',
        );
    }

    public function dispatchTicket(KitchenDispatch $dispatch, User $user, bool $reprint): PrintAttempt
    {
        $setting = $this->setting($dispatch->company_id, $dispatch->branch_id, PrinterPurpose::CustomerTicket);

        return $this->enqueue(
            $setting,
            $this->ticketRenderer->renderDispatch($dispatch, $user),
            $user,
            $reprint,
            $dispatch,
            $dispatch->order,
            $reprint ? 'customer_ticket:dispatch:'.$dispatch->ulid.':reprint:'.Str::ulid() : 'customer_ticket:dispatch:'.$dispatch->ulid.':original',
        );
    }

    public function testPage(PrinterSetting $setting, User $user): PrintAttempt
    {
        return $this->enqueue(
            $setting,
            $this->testRenderer->render((string) $setting->windows_printer_name),
            $user,
            false,
            null,
            null,
            'test_page:'.$setting->ulid.':'.Str::ulid(),
        );
    }

    public function retry(PrintAttempt $attempt): PrintAttempt
    {
        return DB::transaction(function () use ($attempt): PrintAttempt {
            $attempt = PrintAttempt::query()->whereKey($attempt)->lockForUpdate()->firstOrFail();
            $attempt->loadMissing('printerSetting');
            $enabled = $attempt->printerSetting?->is_active === true;

            if ($attempt->status !== PrintAttemptStatus::Failed) {
                return $attempt;
            }

            $attempt->update([
                'status' => $enabled ? PrintAttemptStatus::Pending : PrintAttemptStatus::Failed,
                'available_at' => $enabled ? now() : null,
                'error_message' => $enabled ? null : 'La impresora lógica no está activa.',
                'claimed_by_agent_id' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'claim_token_hash' => null,
            ]);

            return $attempt->refresh();
        });
    }

    private function setting(int $companyId, int $branchId, PrinterPurpose $purpose): ?PrinterSetting
    {
        return PrinterSetting::query()
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('purpose', $purpose->value)->first();
    }

    private function enqueue(
        ?PrinterSetting $setting,
        ThermalDocument $document,
        User $user,
        bool $reprint,
        ?KitchenDispatch $dispatch,
        ?Order $order,
        string $idempotencyKey,
    ): PrintAttempt {
        return DB::transaction(function () use ($setting, $document, $user, $reprint, $dispatch, $order, $idempotencyKey): PrintAttempt {
            if ($dispatch) {
                KitchenDispatch::query()->whereKey($dispatch->getKey())->lockForUpdate()->firstOrFail();
            }
            if ($order) {
                Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            }

            $existing = PrintAttempt::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $purpose = $setting?->purpose ?? ($dispatch ? PrinterPurpose::Kitchen : PrinterPurpose::CustomerTicket);
            $enabled = $setting?->is_active === true;

            return PrintAttempt::query()->create([
                'company_id' => $dispatch?->company_id ?? $order?->company_id ?? $setting?->company_id,
                'branch_id' => $dispatch?->branch_id ?? $order?->branch_id ?? $setting?->branch_id,
                'printer_setting_id' => $setting?->getKey(),
                'kitchen_dispatch_id' => $dispatch?->getKey(),
                'order_id' => $order?->getKey(),
                'purpose' => $purpose,
                'windows_printer_name' => $setting?->windows_printer_name,
                'copies' => $setting?->copies ?? 1,
                'status' => $enabled ? PrintAttemptStatus::Pending : PrintAttemptStatus::Failed,
                'is_reprint' => $reprint,
                'requested_by' => $user->getKey(),
                'attempted_at' => now(),
                'available_at' => $enabled ? now() : null,
                'error_message' => $enabled ? null : 'La impresora lógica no está activa.',
                'idempotency_key' => $idempotencyKey,
                'document_payload' => base64_encode($document->bytes),
            ]);
        });
    }
}
