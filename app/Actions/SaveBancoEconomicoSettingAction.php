<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SaveBancoEconomicoSettingAction
{
    public const URLS = [
        'production' => 'https://apimkt.baneco.com.bo/ApiGateway/',
        'certification' => 'https://apimktdesa.baneco.com.bo/ApiGateway/',
    ];

    public function execute(Company $company, ?Branch $branch, ?User $user, array $input): BankQrSetting
    {
        if ($user && ! $user->memberships()->where('company_id', $company->id)->where('is_active', true)->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])->exists()) {
            throw new AuthorizationException;
        }
        if ($branch && $branch->company_id !== $company->id) {
            throw new AuthorizationException;
        }
        $environment = $input['environment'] ?? '';
        $url = $input['base_url'] ?? '';
        if (! isset(self::URLS[$environment]) || $url !== self::URLS[$environment]) {
            throw new DomainException('La URL debe corresponder exactamente al ambiente bancario seleccionado.');
        }
        $days = filter_var($input['due_days'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 30) {
            throw new DomainException('El vencimiento debe estar entre 1 y 30 días.');
        }
        $branchCode = trim((string) ($input['branch_code'] ?? ''));
        if (mb_strlen($branchCode) > 5) {
            throw new DomainException('El código de sucursal admite como máximo cinco caracteres.');
        }

        return DB::transaction(function () use ($company, $branch, $user, $input, $url, $days, $branchCode): BankQrSetting {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $scope = $company->id.':'.($branch?->id ?? 'company');
            $existing = BankQrSetting::query()->forCompany($company)->where('active_scope', $scope)->lockForUpdate()->first();
            $inherited = $branch && ! $existing ? BankQrSetting::query()->forCompany($company)->where('active_scope', $company->id.':company')->first() : null;
            $credentials = ($existing ?? $inherited)?->credentials ?? [];
            foreach (['username', 'password', 'aes_key', 'account_credit'] as $key) {
                if (isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) !== '') {
                    $credentials[$key] = $input[$key];
                }
            }
            $credentials['branch_code'] = $branchCode;
            $enabled = (bool) ($input['enabled'] ?? false);
            $complete = collect(['username', 'password', 'aes_key', 'account_credit'])->every(fn ($key) => is_string($credentials[$key] ?? null) && trim($credentials[$key]) !== '');
            if ($enabled && (! $complete || strlen($credentials['aes_key']) !== 32)) {
                throw new DomainException('Para habilitar QR completa las credenciales y usa una llave AES de 32 bytes.');
            }
            if ($existing && $existing->credentials === $credentials && ($existing->base_url ?? config('baneco.base_url')) === $url
                && $existing->enabled === $enabled && $existing->due_days === $days
                && $existing->technical_logging === (bool) ($input['technical_logging'] ?? false)) {
                return $existing;
            }
            if ($existing) {
                $existing->update(['active_scope' => null, 'base_url' => $existing->base_url ?? config('baneco.base_url')]);
            }
            $setting = BankQrSetting::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch?->id, 'active_scope' => $scope,
                'credentials' => $credentials, 'base_url' => $url, 'enabled' => $enabled,
                'due_days' => $days, 'technical_logging' => (bool) ($input['technical_logging'] ?? false),
            ]);
            DB::table('bank_qr_setting_audits')->insert(['company_id' => $company->id, 'bank_qr_setting_id' => $setting->id, 'user_id' => $user?->id, 'source' => $user ? 'web' : 'console', 'created_at' => now()]);

            return $setting;
        });
    }
}
