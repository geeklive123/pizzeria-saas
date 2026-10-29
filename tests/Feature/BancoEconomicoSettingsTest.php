<?php

namespace Tests\Feature;

use App\Actions\SaveBancoEconomicoSettingAction;
use App\Enums\MembershipRole;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\BancoEconomicoGateway;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BancoEconomicoSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->for($this->company)->create();
        $this->owner = User::factory()->create();
        Membership::factory()->for($this->company)->for($this->owner)->owner()->create();
        $this->actingAs($this->owner)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
    }

    private function data(string $scope = 'company'): array
    {
        return ['scope' => $scope, 'environment' => 'certification', 'base_url' => SaveBancoEconomicoSettingAction::URLS['certification'],
            'due_days' => '2', 'enabled' => '1', 'technical_logging' => '1', 'branch_code' => 'E0001',
            'replace_username' => '1', 'username' => 'user-private', 'replace_password' => '1', 'password' => 'password-private',
            'replace_aes_key' => '1', 'aes_key' => str_repeat('a', 32), 'replace_account_credit' => '1', 'account_credit' => 'account-private'];
    }

    public function test_save_edit_and_versions_preserve_encrypted_secrets(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $first = BankQrSetting::query()->sole();
        $this->assertStringNotContainsString('password-private', $first->getRawOriginal('credentials'));
        $this->get(route('bank-settings.index'))->assertOk()->assertSee('Configurado')->assertDontSee('password-private')->assertDontSee('account-private')->assertDontSee(str_repeat('a', 32))->assertDontSee('user-private');
        $data = $this->data();
        foreach (['username', 'password', 'aes_key', 'account_credit'] as $key) {
            unset($data['replace_'.$key], $data[$key]);
        }
        $data['due_days'] = '3';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $this->assertNull($first->refresh()->active_scope);
        $current = BankQrSetting::query()->whereNotNull('active_scope')->sole();
        $this->assertSame('password-private', $current->credentials['password']);
        $this->assertSame('password-private', $first->credentials['password']);
        $this->assertSame(3, $current->due_days);
        $this->assertDatabaseCount('bank_qr_setting_audits', 2);
        $this->assertDatabaseHas('bank_qr_setting_audits', ['bank_qr_setting_id' => $current->id, 'user_id' => $this->owner->id]);
    }

    public function test_branch_scope_is_isolated_and_takes_priority(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $branchData = $this->data('branch');
        $branchData['username'] = 'branch-user';
        $this->post(route('bank-settings.store'), $branchData)->assertSessionHasNoErrors();
        $setting = BankQrSetting::forBranch($this->company, $this->branch);
        $this->assertSame('branch-user', $setting->credentials['username']);
        $other = Branch::factory()->for($this->company)->create();
        $this->assertSame('user-private', BankQrSetting::forBranch($this->company, $other)->credentials['username']);
        $this->assertSame($this->branch->id, $setting->branch_id);
    }

    public function test_explicit_replacement_changes_only_selected_secret_and_identical_save_is_idempotent(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $first = BankQrSetting::query()->sole();
        $data = $this->data();
        unset($data['replace_username'], $data['replace_aes_key'], $data['replace_account_credit']);
        $data['password'] = 'new-private-password';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $new = BankQrSetting::query()->whereNotNull('active_scope')->sole();
        $this->assertSame('new-private-password', $new->credentials['password']);
        $this->assertSame('account-private', $new->credentials['account_credit']);
        $this->assertSame('password-private', $first->refresh()->credentials['password']);
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_settings', 2);
        $this->assertDatabaseCount('bank_qr_setting_audits', 2);
    }

    public function test_retired_version_keeps_its_environment_for_existing_operations(): void
    {
        $production = $this->data();
        $production['environment'] = 'production';
        $production['base_url'] = SaveBancoEconomicoSettingAction::URLS['production'];
        $this->post(route('bank-settings.store'), $production)->assertSessionHasNoErrors();
        $previous = BankQrSetting::query()->sole();
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $diagnostic = new BankQrDiagnostic(['bank_qr_id' => 'fake-qr']);
        $diagnostic->setRelation('setting', $previous->refresh());
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/encrypt')) {
                return Http::response('encrypted');
            }
            if (str_contains($request->url(), '/authenticate')) {
                return Http::response(['responseCode' => 0, 'token' => 'fake-token']);
            }

            return Http::response(['responseCode' => 0, 'statusQRCode' => 0]);
        });
        app(BancoEconomicoGateway::class)->status($diagnostic);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), SaveBancoEconomicoSettingAction::URLS['production']) && str_contains($request->url(), '/statusQR/'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), SaveBancoEconomicoSettingAction::URLS['certification']));
    }

    public function test_rejects_unapproved_url_and_incomplete_configuration_but_allows_uncertain_history(): void
    {
        $data = $this->data();
        $data['base_url'] = 'https://apimktdesa.baneco.com.bo.attacker.example/ApiGateway/';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasErrors('configuration');
        $data = $this->data();
        unset($data['aes_key'], $data['replace_aes_key']);
        $this->post(route('bank-settings.store'), $data)->assertSessionHasErrors('configuration');
        $data = $this->data();
        $data['enabled'] = '0';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $setting = BankQrSetting::query()->sole();
        BankQrDiagnostic::query()->create(['company_id' => $this->company->id, 'bank_qr_setting_id' => $setting->id,
            'user_id' => $this->owner->id, 'active_company_id' => $this->company->id, 'status' => 'generation_unknown',
            'commercial_reference' => 'unknown', 'amount' => '1.00', 'expires_at' => now()->addDay()]);
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_settings', 2);
        Http::assertNothingSent();
    }

    public function test_only_admins_can_edit_and_secrets_are_not_flashed_on_validation_failure(): void
    {
        $bad = $this->data();
        $bad['due_days'] = '99';
        $this->post(route('bank-settings.store'), $bad)->assertSessionHasErrors('due_days');
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('aes_key', session()->getOldInput());
        $this->assertArrayNotHasKey('account_credit', session()->getOldInput());
        $cashier = User::factory()->create();
        Membership::factory()->for($this->company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $this->actingAs($cashier)->get(route('bank-settings.index'))->assertForbidden();
        $this->post(route('bank-settings.store'), $this->data())->assertForbidden();
        $other = Company::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($other)->for($otherOwner)->owner()->create();
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $otherBranch->id])
            ->get(route('bank-settings.index'))->assertOk()->assertSee('Sin configurar');
    }

    public function test_authentication_requires_confirmation_and_never_generates_qr_or_leaks_token(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/encrypt')) {
                return Http::response('encrypted');
            }

            return Http::response(['responseCode' => 0, 'token' => 'secret-token']);
        });
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company'])->assertSessionHasErrors('confirm_contact');
        Http::assertNothingSent();
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertRedirect();
        $this->assertArrayNotHasKey('token', session('bank_auth_result'));
        $this->get(route('bank-settings.index', ['tab' => 'authentication']))->assertSee('Autenticación correcta')->assertSee('Autenticación comprobada en esta sesión')->assertDontSee('secret-token');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), SaveBancoEconomicoSettingAction::URLS['certification']));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generateQR'));
    }

    public function test_authentication_bank_rejection_is_sanitized_and_incomplete_configuration_does_not_contact_bank(): void
    {
        $data = $this->data();
        $data['enabled'] = '0';
        unset($data['replace_password'], $data['password']);
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        Log::spy();
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertSessionHasErrors('authentication');
        Http::assertNothingSent();
        $data['enabled'] = '1';
        $data['replace_password'] = '1';
        $data['password'] = 'password-private';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/encrypt')) {
                return Http::response('encrypted');
            }

            return Http::response(['responseCode' => 17, 'message' => 'private-bank-detail'], 200);
        });
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertRedirect();
        $this->get(route('bank-settings.index', ['tab' => 'authentication']))->assertSee('Autenticación fallida')->assertSee('bank_response_17')->assertDontSee('private-bank-detail')->assertDontSee('password-private');
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            $text = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'baneco_response_diagnostic' && ! str_contains($text, 'private-bank-detail') && ! str_contains($text, 'password-private');
        })->once();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generateQR'));
    }

    public function test_gateway_rejects_unapproved_url_even_for_persisted_settings(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $setting = BankQrSetting::query()->sole();
        $setting->update(['base_url' => 'https://apimktdesa.baneco.com.bo.attacker.example/ApiGateway/']);
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertRedirect();
        $this->get(route('bank-settings.index', ['tab' => 'authentication']))->assertSee('invalid_api_url');
        Http::assertNothingSent();
    }

    public function test_username_can_be_replaced_and_connection_failure_is_sanitized(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $data = $this->data();
        unset($data['replace_password'], $data['replace_aes_key'], $data['replace_account_credit']);
        $data['username'] = 'replacement-user';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame('replacement-user', BankQrSetting::query()->whereNotNull('active_scope')->sole()->credentials['username']);
        Http::fake(fn () => throw new ConnectionException('private transport detail'));
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertRedirect();
        $this->get(route('bank-settings.index', ['tab' => 'authentication']))
            ->assertSee('No se pudo conectar')->assertDontSee('private transport detail')->assertDontSee('replacement-user');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generateQR'));
    }

    public function test_uncertain_qr_allows_credential_rotation_and_activation(): void
    {
        config(['baneco.diagnostics_enabled' => true]);
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $old = BankQrSetting::query()->sole();
        $otherBranch = Branch::factory()->for($this->company)->create();
        $other = Company::factory()->create();
        $otherSetting = app(SaveBancoEconomicoSettingAction::class)->execute($other, null, null, $this->data());
        $diagnostic = BankQrDiagnostic::query()->create([
            'company_id' => $this->company->id, 'bank_qr_setting_id' => $old->id,
            'user_id' => $this->owner->id, 'active_company_id' => $this->company->id,
            'status' => 'generation_unknown', 'commercial_reference' => 'unknown-rotation',
            'amount' => '1.00', 'expires_at' => now()->addDay(),
        ]);
        $html = $this->get(route('bank-settings.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="enabled" value="1"\s+checked/', $html);

        $data = $this->data();
        unset($data['enabled']); // A browser omits an unchecked checkbox.
        $data['username'] = 'new-user';
        $data['password'] = 'new-password';
        $data['aes_key'] = str_repeat('b', 32);
        $data['account_credit'] = 'new-account';
        $data['environment'] = 'production';
        $data['base_url'] = SaveBancoEconomicoSettingAction::URLS['production'];
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $current = BankQrSetting::query()->forCompany($this->company)->whereNotNull('active_scope')->sole();
        $this->assertFalse($current->enabled);
        $this->assertSame('new-password', $current->credentials['password']);
        $this->assertStringNotContainsString('new-password', $current->getRawOriginal('credentials'));
        $this->assertSame('password-private', $old->refresh()->credentials['password']);
        $this->assertSame($old->id, $diagnostic->refresh()->bank_qr_setting_id);
        $this->assertSame('generation_unknown', $diagnostic->status);
        $this->assertSame($current->id, BankQrSetting::forBranch($this->company, $otherBranch)->id);
        $this->assertSame('password-private', $otherSetting->refresh()->credentials['password']);
        $this->assertTrue($otherSetting->enabled);
        Http::assertNothingSent();

        $data['enabled'] = '1';
        $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
        $this->assertTrue(BankQrSetting::query()->forCompany($this->company)->whereNotNull('active_scope')->sole()->enabled);
        $this->post(route('bank-qr-diagnostics.store'), ['amount' => '1.00'])->assertSessionHasErrors('confirm_generation');
        $this->assertSame(1, BankQrDiagnostic::query()->forCompany($this->company)->count());
        Http::assertNothingSent();

        Http::fake(fn ($request) => str_contains($request->url(), '/encrypt')
            ? Http::response('encrypted') : Http::response(['responseCode' => 0, 'token' => 'test-token']));
        $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertRedirect();
        $this->assertTrue(session('bank_auth_result.success'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generateQR'));
    }

    public function test_disabled_branch_save_preserves_other_branch_and_unchecked_state_after_validation_error(): void
    {
        $this->post(route('bank-settings.store'), $this->data())->assertSessionHasNoErrors();
        $companySetting = BankQrSetting::query()->sole();
        $branchData = $this->data('branch');
        $branchData['enabled'] = '0';
        $branchData['due_days'] = '99';
        $this->from(route('bank-settings.index', ['scope' => 'branch']))->post(route('bank-settings.store'), $branchData)->assertSessionHasErrors('due_days');
        $html = $this->get(route('bank-settings.index', ['scope' => 'branch']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="enabled" value="1"\s*>/', $html);
        $branchData['due_days'] = '2';
        $branchData['password'] = 'branch-password';
        $this->post(route('bank-settings.store'), $branchData)->assertSessionHasNoErrors();
        $branchSetting = BankQrSetting::forBranch($this->company, $this->branch);
        $this->assertFalse($branchSetting->enabled);
        $this->assertSame('branch-password', $branchSetting->credentials['password']);
        $otherBranch = Branch::factory()->for($this->company)->create();
        $this->assertSame($companySetting->id, BankQrSetting::forBranch($this->company, $otherBranch)->id);
        $this->assertTrue($companySetting->refresh()->enabled);
    }

    public function test_configuration_can_be_enabled_with_an_incident_without_changing_global_flag(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $originalEnvironment = app()->environment();
        app()->instance('env', 'local');
        config(['baneco.enabled' => false, 'baneco.diagnostics_enabled' => true]);
        try {
            $data = $this->data();
            $data['enabled'] = '0';
            $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
            $old = BankQrSetting::query()->sole();
            $diagnostic = BankQrDiagnostic::query()->create([
                'company_id' => $this->company->id, 'bank_qr_setting_id' => $old->id,
                'user_id' => $this->owner->id, 'active_company_id' => $this->company->id,
                'status' => 'generation_unknown', 'commercial_reference' => 'local-unknown',
                'amount' => '1.00', 'expires_at' => now()->addDay(),
            ]);
            $data['enabled'] = '1';
            $data['password'] = 'rotated-password';
            $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
            $current = BankQrSetting::query()->whereNotNull('active_scope')->sole();
            $this->assertTrue($current->enabled);
            $this->assertFalse(config('baneco.enabled'));
            $this->assertSame('password-private', $old->refresh()->credentials['password']);
            $this->assertSame($old->id, $diagnostic->refresh()->bank_qr_setting_id);
            $this->assertSame('generation_unknown', $diagnostic->status);
            $this->get(route('bank-settings.index'))->assertOk()->assertSee('Configuración habilitada')
                ->assertSee('Cobros operativos deshabilitados')->assertSee('Autenticación disponible')
                ->assertSee('Operaciones anteriores pendientes de conciliación');
            $this->post(route('bank-qr-diagnostics.store'), ['amount' => '1.00'])->assertSessionHasErrors('confirm_generation');
            $this->assertDatabaseCount('bank_qr_diagnostics', 1);
            $this->assertDatabaseCount('payments', 0);
            Http::assertNothingSent();
            Http::fake(fn ($request) => str_contains($request->url(), '/encrypt')
                ? Http::response('encrypted') : Http::response(['responseCode' => 0, 'token' => 'test-token']));
            $this->post(route('bank-settings.authenticate'), ['scope' => 'company', 'confirm_contact' => '1'])->assertRedirect();
            $this->assertTrue(session('bank_auth_result.success'));
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generateQR'));

            config(['baneco.enabled' => true]);
            $data['due_days'] = '3';
            $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
            app()->instance('env', 'production');
            config(['baneco.enabled' => false]);
            $this->post(route('bank-settings.store'), $data)->assertSessionHasNoErrors();
            $this->assertFalse(config('baneco.enabled'));
            $this->assertSame('generation_unknown', $diagnostic->refresh()->status);
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }
}
