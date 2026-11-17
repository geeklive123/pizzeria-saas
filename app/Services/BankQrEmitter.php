<?php

namespace App\Services;

use App\Enums\DynamicQrGenerationPhase;
use App\Enums\DynamicQrStatus;
use App\Models\DynamicQrIntent;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shared send boundary. The claimant must atomically arm the prepared intent. */
class BankQrEmitter
{
    public function __construct(private readonly BancoEconomicoGateway $gateway) {}

    public function emit(DynamicQrIntent $candidate, callable $claim, bool $requireEnabled = true): DynamicQrIntent
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('No se puede generar un QR dentro de una transacción sin confirmar.');
        }
        if ($requireEnabled && ! config('baneco.enabled')) {
            throw new DomainException('Banco Económico está desactivado para nuevos QR.');
        }
        $lock = Cache::lock('baneco:intent:'.$candidate->id, 120);
        if (! $lock->get()) {
            return $candidate->refresh();
        }
        try {
            $candidate->refresh();
            if ($candidate->status !== DynamicQrStatus::Creating || $candidate->generation_phase !== DynamicQrGenerationPhase::Prepared->value) {
                return $candidate;
            }
            if (! $claim($candidate)) {
                return $candidate->refresh();
            }
            $candidate->refresh();
            if (DB::transactionLevel() !== 0 || $candidate->status !== DynamicQrStatus::Creating
                || $candidate->generation_phase !== DynamicQrGenerationPhase::SendArmed->value || $candidate->bank_qr_id !== null) {
                throw new DomainException('El envío del QR no está autorizado.');
            }
            try {
                $data = $this->gateway->generate($candidate);
                $candidate->update(['bank_qr_id' => $data['qrId'], 'generation_phase' => DynamicQrGenerationPhase::Generated->value]);
                $image = $this->gateway->image(is_string($data['qrImage'] ?? null) ? $data['qrImage'] : '');
                $candidate->update(['qr_image' => $image, 'status' => DynamicQrStatus::Pending]);
            } catch (BankQrException $exception) {
                $uncertain = $exception->uncertain || $this->gateway->generationAttempted();
                $candidate->update(['status' => $uncertain ? DynamicQrStatus::GenerationUnknown : DynamicQrStatus::Failed, 'issue_code' => $exception->issue,
                    'generation_phase' => $uncertain ? DynamicQrGenerationPhase::GenerationUnknown->value : DynamicQrGenerationPhase::ErrorConfirmed->value]);
                if ($uncertain) {
                    $this->alert($candidate);
                }
            } catch (Throwable) {
                $candidate->update(['status' => DynamicQrStatus::GenerationUnknown, 'issue_code' => 'generation_result_unknown', 'generation_phase' => DynamicQrGenerationPhase::GenerationUnknown->value]);
                $this->alert($candidate);
            }

            return $candidate->refresh();
        } finally {
            $lock->release();
        }
    }

    private function alert(DynamicQrIntent $intent): void
    {
        Log::warning('baneco_reconciliation_required', ['intent' => $intent->ulid, 'company_id' => $intent->company_id, 'issue' => $intent->issue_code]);
    }
}
