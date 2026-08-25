<?php

namespace App\Services;

use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Printing\Contracts\ThermalPrinterTransport;
use App\Printing\Renderers\CustomerTicketRenderer;
use App\Printing\Renderers\KitchenCommandRenderer;
use App\Printing\Renderers\TestPageRenderer;
use App\Printing\ThermalDocument;
use Illuminate\Support\Facades\Log;
use Throwable;

class ThermalPrintingService
{
    public function __construct(
        private readonly ThermalPrinterTransport $transport,
        private readonly KitchenCommandRenderer $kitchenRenderer,
        private readonly CustomerTicketRenderer $ticketRenderer,
        private readonly TestPageRenderer $testRenderer,
    ) {}

    public function kitchen(KitchenDispatch $dispatch, User $user, bool $reprint): PrintAttempt
    {
        $setting = $this->setting($dispatch->company_id, $dispatch->branch_id, PrinterPurpose::Kitchen);
        $document = $this->kitchenRenderer->render($dispatch);

        return $this->deliver($setting, $document, $user, $reprint, $dispatch, null);
    }

    public function ticket(Order $order, User $user, bool $reprint): PrintAttempt
    {
        $setting = $this->setting($order->company_id, $order->branch_id, PrinterPurpose::CustomerTicket);
        $document = $this->ticketRenderer->render($order, $user);

        return $this->deliver($setting, $document, $user, $reprint, null, $order);
    }

    public function testPage(PrinterSetting $setting, User $user): PrintAttempt
    {
        return $this->deliver(
            $setting,
            $this->testRenderer->render((string) $setting->windows_printer_name),
            $user,
            false,
            null,
            null,
        );
    }

    private function setting(int $companyId, int $branchId, PrinterPurpose $purpose): ?PrinterSetting
    {
        return PrinterSetting::query()
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('purpose', $purpose->value)->first();
    }

    private function deliver(
        ?PrinterSetting $setting,
        ThermalDocument $document,
        User $user,
        bool $reprint,
        ?KitchenDispatch $dispatch,
        ?Order $order,
    ): PrintAttempt {
        $purpose = $setting?->purpose ?? ($dispatch ? PrinterPurpose::Kitchen : PrinterPurpose::CustomerTicket);
        $attributes = [
            'company_id' => $dispatch?->company_id ?? $order?->company_id ?? $setting?->company_id,
            'branch_id' => $dispatch?->branch_id ?? $order?->branch_id ?? $setting?->branch_id,
            'printer_setting_id' => $setting?->getKey(),
            'kitchen_dispatch_id' => $dispatch?->getKey(),
            'order_id' => $order?->getKey(),
            'purpose' => $purpose,
            'windows_printer_name' => $setting?->windows_printer_name,
            'copies' => $setting?->copies ?? 1,
            'is_reprint' => $reprint,
            'requested_by' => $user->getKey(),
            'attempted_at' => now(),
        ];

        try {
            if (! $setting?->is_active || blank($setting->windows_printer_name)) {
                throw new \RuntimeException('La impresora no está activa o no tiene un nombre configurado.');
            }
            $this->transport->send($setting->windows_printer_name, $document->bytes, $setting->copies);

            return PrintAttempt::query()->create($attributes + [
                'status' => PrintAttemptStatus::Succeeded,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Falló la impresión térmica.', [
                'purpose' => $purpose->value,
                'company_id' => $attributes['company_id'],
                'branch_id' => $attributes['branch_id'],
                'kitchen_dispatch_id' => $attributes['kitchen_dispatch_id'],
                'order_id' => $attributes['order_id'],
                'exception' => $exception,
            ]);

            return PrintAttempt::query()->create($attributes + [
                'status' => PrintAttemptStatus::Failed,
                'error_message' => 'No se pudo enviar el documento a la impresora configurada.',
            ]);
        }
    }
}
