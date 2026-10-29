<?php

namespace App\Http\Controllers;

use App\Actions\SaveBancoEconomicoSettingAction;
use App\Enums\MembershipRole;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\DynamicQrIntent;
use App\Services\BancoEconomicoGateway;
use App\Services\BankQrException;
use App\Support\CompanyContext;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BancoEconomicoSettingsController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureAdmin();
        $scope = $request->query('scope') === 'branch' ? 'branch' : 'company';
        $setting = $this->setting($scope);
        $effective = $setting ?? ($scope === 'branch' ? $this->setting('company') : null);
        $credentials = $effective?->credentials ?? [];
        $configured = [];
        foreach (['username', 'password', 'aes_key', 'account_credit'] as $key) {
            $configured[$key] = is_string($credentials[$key] ?? null) && trim($credentials[$key]) !== '';
        }
        $branchCode = $credentials['branch_code'] ?? '';
        $url = $effective?->base_url ?? config('baneco.base_url');
        $environment = array_search($url, SaveBancoEconomicoSettingAction::URLS, true) ?: 'unknown';
        $tab = $request->query('tab') === 'authentication' ? 'authentication' : 'configuration';
        $complete = collect($configured)->every(fn ($value) => $value) && strlen($credentials['aes_key'] ?? '') === 32;
        $authAvailable = $configured['username'] && $configured['password'] && $configured['aes_key'] && strlen($credentials['aes_key']) === 32;
        $qrVerified = $effective && BankQrDiagnostic::query()->forCompany($this->company())
            ->where('bank_qr_setting_id', $effective->id)->whereNotNull('bank_qr_id')->where('generation_response_code', '0')->exists();
        $blocked = BankQrDiagnostic::query()->forCompany($this->company())->where(fn ($query) => $query->whereNotNull('active_company_id')->orWhere('status', 'generation_unknown'))->exists()
            || DynamicQrIntent::query()->forCompany($this->company())->whereIn('status', ['generation_unknown', 'reconciliation_required'])->exists();

        return response()->view('bank-settings.index', compact('setting', 'effective', 'configured', 'scope', 'url', 'environment', 'blocked', 'branchCode', 'tab', 'complete', 'authAvailable', 'qrVerified'))
            ->header('Cache-Control', 'private, no-store, no-cache, max-age=0, must-revalidate');
    }

    public function store(Request $request, SaveBancoEconomicoSettingAction $save)
    {
        $this->ensureAdmin();
        $rules = [
            'scope' => ['required', 'in:company,branch'], 'environment' => ['required', 'in:production,certification'],
            'base_url' => ['required', 'string', 'max:160'], 'due_days' => ['required', 'integer', 'between:1,30'],
            'branch_code' => ['nullable', 'string', 'max:5'], 'enabled' => ['nullable', 'boolean'],
            'technical_logging' => ['nullable', 'boolean'],
        ];
        foreach (['username', 'password', 'aes_key', 'account_credit'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:512'];
            $rules['replace_'.$key] = ['nullable', 'boolean'];
        }
        $safeInput = $request->except(['username', 'password', 'aes_key', 'account_credit']);
        $safeInput['enabled'] = $request->boolean('enabled', false);
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($safeInput);
        }
        $data = $validator->validated();
        foreach (['username', 'password', 'aes_key', 'account_credit'] as $key) {
            if (! $request->boolean('replace_'.$key)) {
                unset($data[$key]);
            } elseif (! is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                return back()->withErrors([$key => 'Escribe el nuevo valor o desmarca la sustitución.']);
            }
        }
        // An omitted checkbox is false, regardless of the previously stored setting.
        $data['enabled'] = $request->boolean('enabled', false);
        $data['technical_logging'] = $request->boolean('technical_logging');
        try {
            $save->execute($this->company(), $data['scope'] === 'branch' ? $this->branch() : null, $request->user(), $data);
        } catch (DomainException $exception) {
            return back()->withErrors(['configuration' => $exception->getMessage()])
                ->withInput($safeInput);
        }

        return redirect()->route('bank-settings.index', ['scope' => $data['scope']])->with('status', 'Configuración cifrada guardada.');
    }

    public function authenticate(Request $request, BancoEconomicoGateway $gateway)
    {
        $this->ensureAdmin();
        $validator = Validator::make($request->all(), ['scope' => ['required', 'in:company,branch'], 'confirm_contact' => ['required', 'accepted']]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $scope = $request->input('scope');
        $setting = $scope === 'branch' ? BankQrSetting::forBranch($this->company(), $this->branch()) : $this->setting($scope);
        $credentials = $setting?->credentials ?? [];
        if (! $setting || collect(['username', 'password', 'aes_key'])->contains(fn ($key) => ! is_string($credentials[$key] ?? null) || trim($credentials[$key]) === '') || strlen($credentials['aes_key']) !== 32) {
            return back()->withErrors(['authentication' => 'Completa las credenciales antes de probar autenticación.']);
        }
        try {
            $gateway->testAuthentication($setting);
            $result = ['success' => true, 'operation' => 'authenticate'] + $gateway->responseMetadata();
        } catch (BankQrException $exception) {
            $metadata = $gateway->responseMetadata();
            $result = ['success' => false, 'operation' => $exception->operation ?? 'authenticate', 'issue' => $exception->issue,
                'http_status' => $exception->httpStatus ?? $metadata['http_status'], 'response_code' => $exception->responseCode ?? $metadata['response_code']];
        } catch (Throwable) {
            $result = ['success' => false, 'operation' => 'authenticate', 'issue' => 'authentication_unavailable', 'http_status' => null, 'response_code' => null];
        }

        $result['setting_id'] = $setting->id;
        $result['environment'] = array_search($setting->base_url ?? config('baneco.base_url'), SaveBancoEconomicoSettingAction::URLS, true) ?: 'desconocido';
        $result['tested_at'] = now()->timezone('America/La_Paz')->format('d/m/Y H:i:s');

        return redirect()->route('bank-settings.index', ['scope' => $scope, 'tab' => 'authentication'])->with('bank_auth_result', $result);
    }

    private function setting(string $scope): ?BankQrSetting
    {
        $scopeId = $this->company()->id.':'.($scope === 'branch' ? $this->branch()->id : 'company');

        return BankQrSetting::query()->forCompany($this->company())->where('active_scope', $scopeId)->first();
    }

    private function ensureAdmin(): void
    {
        abort_unless(in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true), 403);
    }
}
