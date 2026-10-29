<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\BancoEconomicoGateway;
use App\Services\BankQrException;
use App\Services\BankQrImageInspection;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BankQrDiagnosticAction
{
    public function __construct(private readonly BancoEconomicoGateway $bank, private readonly BankQrImageInspection $images) {}

    public function generate(Company $company, User $user, string $amount, ?Branch $branch, string $requestKey): BankQrDiagnostic
    {
        $this->authorize($company, $user);
        if (! config('baneco.diagnostics_enabled')) {
            throw new DomainException('Las pruebas bancarias están desactivadas.');
        }
        if (! preg_match('/^1(?:\.0{1,2})?$/D', $amount) || ! Str::isUlid($requestKey)) {
            throw new DomainException('La prueba requiere Bs 1 y una clave de operación válida.');
        }
        if ($branch && $branch->company_id !== $company->id) {
            throw new AuthorizationException;
        }
        $created = false;
        $record = DB::transaction(function () use ($company, $user, $amount, $branch, $requestKey, &$created) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $reference = 'D'.strtoupper($requestKey);
            $existing = BankQrDiagnostic::query()->where('commercial_reference', $reference)->first();
            if ($existing) {
                if ($existing->company_id !== $company->id || $existing->user_id !== $user->id) {
                    throw new AuthorizationException;
                }

                return $existing;
            }
            $setting = $branch ? BankQrSetting::forBranch($company, $branch) : BankQrSetting::query()->forCompany($company)->whereNull('branch_id')->whereNotNull('active_scope')->first();
            if (! $setting) {
                throw new DomainException('Falta la configuración bancaria activa de la empresa.');
            }

            $record = BankQrDiagnostic::query()->create([
                'company_id' => $company->id, 'active_company_id' => $company->id,
                'bank_qr_setting_id' => $setting->id, 'user_id' => $user->id,
                'amount' => (string) BigDecimal::of($amount)->toScale(2),
                'commercial_reference' => $reference,
                'status' => 'creating', 'expires_at' => now()->timezone('America/La_Paz')->addDays($setting->due_days ?? 1)->endOfDay()->utc(),
            ]);
            $this->event($record, $user, 'generate');
            $created = true;

            return $record;
        });
        if (! $created) {
            return $record;
        }
        try {
            $response = $this->bank->generate($record);
            $original = is_string($response['qrImage'] ?? null) ? $response['qrImage'] : null;
            $record->update(['bank_qr_id' => $response['qrId'], 'original_qr_image' => $original, 'generation_response_code' => '0', 'generation_http_status' => $this->bank->responseMetadata()['http_status'], 'http_status' => $this->bank->responseMetadata()['http_status']]);
            $inspection = $this->images->inspect($original ?? '');
            if (! $inspection['base64_valid'] || ! $inspection['image_valid']) {
                throw new BankQrException('invalid_qr_image', true, operation: 'image');
            }
            $record->update(['status' => 'pending', 'operation' => 'generate', 'response_code' => '0']);
        } catch (BankQrException $exception) {
            $uncertain = $exception->uncertain || $this->bank->generationAttempted();
            if ($exception->operation === 'generate') {
                $record->update(['generation_http_status' => $exception->httpStatus ?? $this->bank->responseMetadata()['http_status']]);
            }
            $this->failure($record, $exception, $uncertain ? 'generation_unknown' : 'failed');
            if (! $uncertain && ! $record->bank_qr_id) {
                $record->update(['active_company_id' => null]);
            }
        } catch (Throwable) {
            $record->update(['status' => 'generation_unknown', 'operation' => 'generate', 'issue_code' => 'generation_result_unknown']);
        }

        $this->event($record->refresh(), $user, 'generate');

        return $record;
    }

    public function check(BankQrDiagnostic $record, User $user, bool $cancel = false): BankQrDiagnostic
    {
        $this->authorize($record->company, $user);
        $lock = Cache::lock('baneco:diagnostic:'.$record->id, 120);
        if (! $lock->get()) {
            $this->event($record->refresh(), $user, $cancel ? 'cancel' : 'status');

            return $record;
        }
        try {
            $record->refresh();
            if (! $record->bank_qr_id || in_array($record->status, ['paid', 'cancelled', 'confirmed_not_generated', 'failed'], true)) {
                $this->event($record, $user, $cancel ? 'cancel' : 'status');

                return $record;
            }
            try {
                $this->applyStatus($record, $this->bank->status($record));
                if ($cancel && $record->status === 'pending') {
                    $this->bank->cancel($record);
                    $this->applyStatus($record, $this->bank->status($record));
                    if ($record->status === 'pending') {
                        $record->update(['operation' => 'cancel', 'issue_code' => 'cancellation_unconfirmed']);
                    }
                }
            } catch (BankQrException $exception) {
                $this->failure($record, $exception, $record->status, $cancel ? 'cancel' : 'status');
            }

            $this->event($record->refresh(), $user, $cancel ? 'cancel' : 'status');

            return $record;
        } finally {
            $lock->release();
        }
    }

    public function recordExternalConfirmation(BankQrDiagnostic $record, User $user, string $details, string $contact, string $bankCase, string $date): void
    {
        $this->authorize($record->company, $user);
        $this->validateEvidence($details);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (trim($contact) === '' || trim($bankCase) === '' || mb_strlen($contact) > 120 || mb_strlen($bankCase) > 120
            || ! $parsed || $parsed->format('Y-m-d') !== $date || $date > now()->toDateString()) {
            throw new DomainException('Indica contacto, número de caso y fecha válida de la confirmación bancaria.');
        }
        $details .= "\nFuente: ".$contact."\nCaso: ".$bankCase."\nFecha: ".$date;
        DB::transaction(function () use ($record, $user, $details): void {
            $record = $this->lockUnknown($record);
            $this->addEvidence($record, $user, 'external_no_qr', $details);
        });
    }

    public function verifyCandidate(BankQrDiagnostic $record, User $user, string $candidate, string $details): void
    {
        $this->authorize($record->company, $user);
        $this->validateEvidence($details);
        if (! preg_match('/^[A-Za-z0-9_-]{1,190}$/D', $candidate)) {
            throw new DomainException('El identificador QR no tiene un formato válido.');
        }
        $lock = Cache::lock('baneco:diagnostic:'.$record->id, 120);
        if (! $lock->get()) {
            throw new DomainException('Hay una consulta en curso. Inténtalo más tarde.');
        }
        try {
            DB::transaction(function () use ($record, $user, $candidate, $details): void {
                $record = $this->lockUnknown($record);
                $this->addEvidence($record, $user, 'qr_candidate', $details, $candidate);
            });
            $probe = $record->replicate();
            $probe->setRelation('setting', $record->setting);
            $probe->bank_qr_id = $candidate;
            try {
                $data = $this->bank->status($probe);
                $status = $data['statusQrCode'] ?? $data['statusQRCode'] ?? null;
                $outcome = match ($status) {
                    '0' => 'pending',
                    '9' => 'cancelled',
                    '1' => $this->candidatePaymentMatches($record, $candidate, $data) ? 'paid_details_match' : 'paid_mismatch',
                    default => 'unknown',
                };
                $metadata = $this->bank->responseMetadata();
                $this->addEvidence($record, $user, 'qr_lookup', 'Consulta API; no confirma la asociación con la referencia comercial.', $candidate, $outcome, $metadata['http_status'], $metadata['response_code']);
            } catch (BankQrException $exception) {
                $this->addEvidence($record, $user, 'qr_lookup', 'Consulta API sin confirmación.', $candidate, 'error', $exception->httpStatus, $exception->responseCode, $exception->issue);
            }
        } finally {
            $lock->release();
        }
    }

    public function authorizeNotGenerated(BankQrDiagnostic $record, User $user, string $reason, bool $confirmed): void
    {
        $this->authorize($record->company, $user);
        $this->validateEvidence($reason);
        if (! $confirmed) {
            throw new DomainException('Debes confirmar expresamente la verificación externa y autorizar el cierre.');
        }
        $lock = Cache::lock('baneco:diagnostic:'.$record->id, 120);
        if (! $lock->get()) {
            throw new DomainException('Hay una consulta en curso. Inténtalo más tarde.');
        }
        try {
            DB::transaction(function () use ($record, $user, $reason): void {
                $record = $this->lockUnknown($record);
                $evidence = DB::table('bank_qr_reconciliation_evidence')->where('company_id', $record->company_id)->where('diagnostic_id', $record->id);
                if (! (clone $evidence)->where('kind', 'external_no_qr')->exists()) {
                    throw new DomainException('Registra primero la confirmación externa documentada del banco.');
                }
                if ((clone $evidence)->where('kind', 'qr_candidate')->exists()) {
                    throw new DomainException('Existe un QR candidato; la incidencia requiere revisión adicional y no puede cerrarse como no generada.');
                }
                $this->addEvidence($record, $user, 'approval_no_qr', $reason);
                $record->update(['status' => 'confirmed_not_generated', 'active_company_id' => null, 'operation' => 'reconcile', 'issue_code' => null]);
                $this->event($record, $user, 'reconcile');
            });
        } finally {
            $lock->release();
        }
    }

    private function candidatePaymentMatches(BankQrDiagnostic $record, string $candidate, array $data): bool
    {
        $payment = $data['payment'] ?? null;
        if (is_array($payment) && array_is_list($payment)) {
            $payment = count($payment) === 1 ? $payment[0] : null;
        }
        $amount = is_array($payment) ? ($payment['amount'] ?? null) : null;

        return is_array($payment) && ($payment['qrId'] ?? null) === $candidate && ($payment['currency'] ?? null) === 'BOB'
            && is_string($amount) && preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)
            && BigDecimal::of($amount)->isEqualTo($record->amount);
    }

    private function lockUnknown(BankQrDiagnostic $record): BankQrDiagnostic
    {
        Company::query()->lockForUpdate()->findOrFail($record->company_id);
        $record = BankQrDiagnostic::query()->forCompany($record->company_id)->lockForUpdate()->findOrFail($record->id);
        if ($record->status !== 'generation_unknown' || $record->bank_qr_id !== null) {
            throw new DomainException('Esta operación no es una generación incierta sin identificador.');
        }

        return $record;
    }

    private function validateEvidence(string $details): void
    {
        if (mb_strlen(trim($details)) < 20 || mb_strlen($details) > 2500) {
            throw new DomainException('Documenta la fuente, fecha y confirmación bancaria con suficiente detalle.');
        }
    }

    private function addEvidence(BankQrDiagnostic $record, User $user, string $kind, string $details, ?string $candidate = null, ?string $status = null, ?int $http = null, ?string $code = null, ?string $issue = null): void
    {
        DB::table('bank_qr_reconciliation_evidence')->insert([
            'diagnostic_id' => $record->id, 'company_id' => $record->company_id, 'user_id' => $user->id,
            'kind' => $kind, 'details' => Crypt::encryptString($details),
            'candidate_qr_id' => $candidate === null ? null : Crypt::encryptString($candidate),
            'bank_status' => $status, 'http_status' => $http, 'response_code' => $code, 'issue_code' => $issue,
            'created_at' => now(),
        ]);
    }

    private function applyStatus(BankQrDiagnostic $record, array $data): void
    {
        if ($record->status === 'reconciliation_required') {
            return;
        }
        $metadata = $this->bank->responseMetadata();
        $status = $data['statusQrCode'] ?? $data['statusQRCode'] ?? null;
        if ($status === '9') {
            $record->update(['status' => 'cancelled', 'active_company_id' => null, 'operation' => 'status', 'http_status' => $metadata['http_status'], 'response_code' => '0', 'issue_code' => null]);
        } elseif ($status === '1') {
            $payment = $data['payment'] ?? null;
            if (is_array($payment) && array_is_list($payment)) {
                $payment = count($payment) === 1 ? $payment[0] : null;
            }
            $amount = is_array($payment) ? ($payment['amount'] ?? null) : null;
            $valid = is_array($payment) && ($payment['qrId'] ?? null) === $record->bank_qr_id
                && ($payment['currency'] ?? null) === 'BOB' && is_string($amount)
                && preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount) && BigDecimal::of($amount)->isEqualTo($record->amount);
            $record->update(['status' => $valid ? 'paid' : 'reconciliation_required', 'active_company_id' => $valid ? null : $record->company_id, 'operation' => 'status', 'http_status' => $metadata['http_status'], 'response_code' => '0', 'issue_code' => $valid ? null : 'bank_payment_mismatch']);
        } elseif ($status === '0') {
            $record->update(['status' => 'pending', 'operation' => 'status', 'http_status' => $metadata['http_status'], 'response_code' => '0', 'issue_code' => null]);
        } else {
            $record->update(['status' => 'reconciliation_required', 'operation' => 'status', 'http_status' => $metadata['http_status'], 'response_code' => '0', 'issue_code' => 'unknown_bank_status']);
        }
    }

    private function failure(BankQrDiagnostic $record, BankQrException $exception, string $status, string $fallback = 'generate'): void
    {
        $metadata = $this->bank->responseMetadata();
        $record->update(['status' => $status, 'operation' => $exception->operation ?? $fallback, 'http_status' => $exception->httpStatus ?? $metadata['http_status'], 'response_code' => $exception->responseCode ?? $metadata['response_code'], 'issue_code' => $exception->issue]);
    }

    private function event(BankQrDiagnostic $record, User $user, string $operation): void
    {
        DB::table('bank_qr_diagnostic_events')->insert([
            'diagnostic_id' => $record->id, 'company_id' => $record->company_id, 'user_id' => $user->id,
            'operation' => $operation, 'status' => $record->status, 'http_status' => $record->http_status,
            'response_code' => $record->response_code, 'issue_code' => $record->issue_code, 'created_at' => now(),
        ]);
    }

    private function authorize(Company $company, User $user): void
    {
        $membership = $user->memberships()->where('company_id', $company->id)->where('is_active', true)->first();
        if (! $membership || ! in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)) {
            throw new AuthorizationException;
        }
    }
}
