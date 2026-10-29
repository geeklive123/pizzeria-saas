<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankQrDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $owner;

    private string $mode = 'success';

    private int $authCount = 0;

    private ?string $originalImage = null;

    private string $requestKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestKey = (string) Str::ulid();
        $this->withoutVite();
        config(['baneco.diagnostics_enabled' => true]);
        Http::preventStrayRequests();
        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->for($this->company)->create();
        $this->owner = User::factory()->create();
        Membership::factory()->for($this->company)->for($this->owner)->owner()->create();
        BankQrSetting::query()->create(['company_id' => $this->company->id, 'active_scope' => $this->company->id.':company', 'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/encrypt')) {
                return Http::response('encrypted');
            }
            if (str_contains($request->url(), '/authenticate')) {
                $this->authCount++;
                if ($this->mode === 'reauth-fails' && $this->authCount > 1) {
                    return Http::response(['responseCode' => 5], 401);
                }

                return Http::response(['responseCode' => 0, 'token' => 'fake-token']);
            }
            if (str_contains($request->url(), '/generateQR')) {
                if ($this->mode === 'timeout') {
                    return Http::failedConnection();
                }
                if ($this->mode === 'error') {
                    return Http::response(['responseCode' => 17, 'message' => 'private'], 200);
                }
                if ($this->mode === 'reauth-fails') {
                    return Http::response([], 401);
                }

                return Http::response(['responseCode' => 0, 'qrId' => 'fake-qr', 'qrImage' => $this->mode === 'bad-image' ? 'invalid' : ($this->originalImage ?? 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF/8AAAAASUVORK5CYII=')]);
            }
            if (str_contains($request->url(), '/cancelQR')) {
                $this->mode = 'cancelled';

                return Http::response(['responseCode' => 0]);
            }
            if (str_contains($request->url(), '/statusQR/')) {
                if ($this->mode === 'status-timeout') {
                    return Http::failedConnection();
                }
                if ($this->mode === 'paid' || $this->mode === 'mismatch') {
                    return Http::response(['responseCode' => 0, 'statusQRCode' => 1, 'payment' => [['qrId' => 'fake-qr', 'amount' => $this->mode === 'paid' ? '1.00' : '2.00', 'currency' => 'BOB']]]);
                }

                return Http::response(['responseCode' => 0, 'statusQRCode' => $this->mode === 'cancelled' ? 9 : 0]);
            }
            $this->fail('Unexpected HTTP request.');
        });
    }

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
    }

    private function payload(): array
    {
        return ['amount' => '1.00', 'request_key' => $this->requestKey, 'confirm_generation' => '1'];
    }

    public function test_owner_can_generate_check_and_cancel_without_sales_or_cash(): void
    {
        $this->login($this->owner)->get(route('bank-qr-diagnostics.index'))->assertOk()->assertSee('1.00');
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->assertSame('pending', $record->status);
        $this->assertSame('fake-qr', $record->bank_qr_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/generateQR') && str_contains($r->body(), '"amount":1.00') && $r['singleUse'] === true && $r['modifyAmount'] === false);
        $this->get(route('bank-qr-diagnostics.index'))->assertSee('fake-qr')->assertSee($record->commercial_reference)->assertSee('Copiar Base64 completo')->assertDontSee('<img src="data:image/', false);
        $this->post(route('bank-qr-diagnostics.check', $record->ulid))->assertRedirect();
        $this->post(route('bank-qr-diagnostics.cancel', $record->ulid))->assertRedirect();
        $this->assertSame('cancelled', $record->refresh()->status);
        $this->assertNull($record->active_company_id);
        $this->assertDatabaseCount('bank_qr_diagnostic_events', 4);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_uncertain_generation_is_not_repeated_and_is_sanitized(): void
    {
        $this->mode = 'error';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->assertSame('generation_unknown', $record->status);
        $this->assertSame('17', $record->response_code);
        $this->assertSame(200, $record->http_status);
        $this->get(route('bank-qr-diagnostics.index'))->assertSee('bank_response_17')->assertDontSee('private');
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
        $this->post(route('bank-qr-diagnostics.cancel', $record->ulid))->assertRedirect();
        $this->assertSame('generation_unknown', $record->refresh()->status);
    }

    public function test_permission_feature_flag_and_company_isolation(): void
    {
        $cashier = User::factory()->create();
        Membership::factory()->for($this->company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $this->login($cashier)->get(route('bank-qr-diagnostics.index'))->assertForbidden();
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertForbidden();
        $admin = User::factory()->create();
        Membership::factory()->for($this->company)->for($admin)->create(['role' => MembershipRole::Admin]);
        config(['baneco.diagnostics_enabled' => false]);
        $this->login($admin)->get(route('bank-qr-diagnostics.index'))->assertOk()->assertSee('Pruebas bancarias desactivadas');
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasErrors('amount');
        Http::assertNothingSent();
        config(['baneco.diagnostics_enabled' => true]);
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $other = Company::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $otherOwner = User::factory()->create();
        Membership::factory()->for($other)->for($otherOwner)->owner()->create();
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $otherBranch->id])
            ->get(route('bank-qr-diagnostics.index'))->assertOk()->assertDontSee($record->original_qr_image);
        $this->post(route('bank-qr-diagnostics.check', $record->ulid))->assertNotFound();
    }

    public function test_timeout_does_not_retry_the_same_generation(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->assertSame('generation_unknown', $record->status);
        $this->assertSame('generate', $record->operation);
        $this->assertSame('bank_unreachable', $record->issue_code);
        $this->assertNull($record->bank_qr_id);
        $this->get(route('bank-qr-diagnostics.index'))->assertOk()->assertSee($record->commercial_reference)
            ->assertSee('Puedes generar una prueba nueva');
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
    }

    public function test_documented_external_confirmation_requires_explicit_admin_authorization(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $url = route('bank-qr-diagnostics.reconcile', $record->ulid);
        $reason = 'Banco confirmó por ticket 123 el día indicado que no existe QR.';
        $this->post($url, ['action' => 'approve', 'details' => $reason, 'confirmed' => '1'])->assertSessionHasErrors('reconciliation');
        $this->post($url, ['action' => 'evidence', 'details' => $reason])->assertSessionHasErrors(['bank_contact', 'bank_case', 'bank_date']);
        $this->post($url, ['action' => 'evidence', 'details' => $reason, 'bank_contact' => 'Banco mesa de ayuda', 'bank_case' => '123', 'bank_date' => now()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('generation_unknown', $record->refresh()->status);
        $this->post($url, ['action' => 'approve', 'details' => $reason])->assertSessionHasErrors('reconciliation');
        $this->post($url, ['action' => 'approve', 'details' => $reason, 'confirmed' => '1'])->assertRedirect();
        $this->assertSame('confirmed_not_generated', $record->refresh()->status);
        $this->assertNull($record->active_company_id);
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
        $this->assertDatabaseCount('bank_qr_reconciliation_evidence', 2);
        $this->assertStringNotContainsString($reason, DB::table('bank_qr_reconciliation_evidence')->first()->details);
        $this->assertDatabaseHas('bank_qr_diagnostic_events', ['diagnostic_id' => $record->id, 'operation' => 'reconcile', 'user_id' => $this->owner->id]);
        $this->assertDatabaseCount('payments', 0);
        $this->post($url, ['action' => 'approve', 'details' => $reason, 'confirmed' => '1'])->assertSessionHasErrors('reconciliation');
    }

    public function test_candidate_is_consulted_without_associating_it_or_creating_a_payment(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $before = Http::recorded(fn ($request) => str_contains($request->url(), '/generateQR'))->count();
        $this->mode = 'paid';
        $url = route('bank-qr-diagnostics.reconcile', $record->ulid);
        $details = 'Banco informó el identificador por ticket 123 y fecha 28 de octubre.';
        $this->post($url, ['action' => 'verify', 'candidate_qr_id' => 'fake-qr', 'details' => $details])->assertRedirect();
        $this->assertSame('generation_unknown', $record->refresh()->status);
        $this->assertNull($record->bank_qr_id);
        $this->assertDatabaseHas('bank_qr_reconciliation_evidence', ['diagnostic_id' => $record->id, 'kind' => 'qr_lookup', 'bank_status' => 'paid_details_match']);
        $this->assertSame($before, Http::recorded(fn ($request) => str_contains($request->url(), '/generateQR'))->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $this->post($url, ['action' => 'evidence', 'details' => $details, 'bank_contact' => 'Banco mesa de ayuda', 'bank_case' => '123', 'bank_date' => now()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['action' => 'approve', 'details' => $details, 'confirmed' => '1'])->assertSessionHasErrors('reconciliation');
        $this->assertSame('generation_unknown', $record->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_reconciliation_rejects_unauthorized_users_and_other_companies(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $cashier = User::factory()->create();
        Membership::factory()->for($this->company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $payload = ['action' => 'evidence', 'details' => 'Evidencia externa de banco suficientemente extensa.'];
        $this->login($cashier)->post(route('bank-qr-diagnostics.reconcile', $record->ulid), $payload)->assertForbidden();
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($other)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id])
            ->post(route('bank-qr-diagnostics.reconcile', $record->ulid), $payload)->assertNotFound();
        $this->assertDatabaseCount('bank_qr_reconciliation_evidence', 0);
        $this->assertSame('generation_unknown', $record->refresh()->status);
    }

    public function test_candidate_statuses_and_failed_lookup_never_close_or_cancel_the_incident(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $url = route('bank-qr-diagnostics.reconcile', $record->ulid);
        $payload = ['action' => 'verify', 'candidate_qr_id' => 'fake-qr', 'details' => 'Banco remitió candidato por ticket 123 en fecha 28 de octubre.'];
        foreach (['success' => 'pending', 'cancelled' => 'cancelled', 'mismatch' => 'paid_mismatch', 'status-timeout' => 'error'] as $mode => $expected) {
            $this->mode = $mode;
            $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('bank_qr_reconciliation_evidence', ['diagnostic_id' => $record->id, 'kind' => 'qr_lookup', 'bank_status' => $expected]);
            $this->assertSame('generation_unknown', $record->refresh()->status);
            $this->assertNull($record->bank_qr_id);
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
    }

    public function test_same_request_is_idempotent_even_if_active_marker_is_missing(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $record->update(['active_company_id' => null]);
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
    }

    public function test_invalid_image_keeps_qr_id_and_sanitized_response_metadata(): void
    {
        $this->mode = 'bad-image';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->assertSame('generation_unknown', $record->status);
        $this->assertSame('fake-qr', $record->bank_qr_id);
        $this->assertSame('image', $record->operation);
        $this->assertSame(200, $record->http_status);
        $this->assertSame('0', $record->response_code);
        $this->assertSame('invalid_qr_image', $record->issue_code);
        $this->assertNull($record->qr_image);
        $this->assertSame('invalid', $record->original_qr_image);
    }

    public function test_original_long_base64_is_encrypted_and_shown_exactly_without_rendering_an_image(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF/8AAAAASUVORK5CYII=', true);
        $this->originalImage = 'data:image/png;base64,'.base64_encode($png.str_repeat('x', 100000));
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->assertSame('pending', $record->status);
        $this->assertSame($this->originalImage, $record->original_qr_image);
        $this->assertStringNotContainsString($this->originalImage, $record->getRawOriginal('original_qr_image'));
        $this->assertArrayNotHasKey('original_qr_image', $record->toArray());
        $response = $this->get(route('bank-qr-diagnostics.index'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')->assertSee('Base64: válido')->assertSee('image/png')->assertSee('responseCode de generación: 0')->assertSee('Longitud original: '.strlen($this->originalImage).' bytes')->assertDontSee('<img src="data:image/', false);
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-bank-qr-original[^>]*>(.*?)<\/textarea>/s', $response->getContent());
        preg_match('/<textarea[^>]*data-bank-qr-original[^>]*>(.*?)<\/textarea>/s', $response->getContent(), $matches);
        $this->assertSame($this->originalImage, html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_failed_reauthentication_after_generation_attempt_keeps_lock(): void
    {
        $this->mode = 'reauth-fails';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->assertSame('generation_unknown', $record->status);
        $this->assertSame('generate', $record->operation);
        $this->assertNotNull($record->active_company_id);
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_paid_qr_is_not_cancelled_and_mismatch_stays_locked(): void
    {
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->mode = 'mismatch';
        $this->post(route('bank-qr-diagnostics.cancel', $record->ulid))->assertRedirect();
        $this->assertSame('reconciliation_required', $record->refresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
        $this->mode = 'paid';
        $this->post(route('bank-qr-diagnostics.check', $record->ulid))->assertRedirect();
        $this->assertSame('reconciliation_required', $record->refresh()->status);
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_confirmed_payment_is_only_diagnostic_and_releases_the_test_lock(): void
    {
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $this->mode = 'paid';
        $this->post(route('bank-qr-diagnostics.cancel', $record->ulid))->assertRedirect();
        $this->assertSame('paid', $record->refresh()->status);
        $this->assertNull($record->active_company_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_independent_confirmed_test_uses_new_reference_and_preserves_uncertain_history(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $old = BankQrDiagnostic::query()->sole();
        $before = Http::recorded(fn ($request) => str_contains($request->url(), '/generateQR'))->count();
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame($before, Http::recorded(fn ($request) => str_contains($request->url(), '/generateQR'))->count());
        $this->requestKey = (string) Str::ulid();
        $this->mode = 'success';
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $new = BankQrDiagnostic::query()->where('id', '!=', $old->id)->sole();
        $this->assertNotSame($old->commercial_reference, $new->commercial_reference);
        $this->assertSame('generation_unknown', $old->refresh()->status);
        $this->assertSame('pending', $new->status);
        $this->assertSame('fake-qr', $new->bank_qr_id);
        $this->assertNotNull($new->original_qr_image);
        $this->assertStringNotContainsString($new->original_qr_image, $new->getRawOriginal('original_qr_image'));
        $this->assertSame(200, $new->http_status);
        $this->assertSame(200, $new->generation_http_status);
        $this->assertSame('0', $new->generation_response_code);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->post(route('bank-qr-diagnostics.store'), ['amount' => '1.00', 'request_key' => (string) Str::ulid()])->assertSessionHasErrors('confirm_generation');
        $this->assertDatabaseCount('bank_qr_diagnostics', 2);
    }

    public function test_existing_in_flight_request_is_never_generated_again(): void
    {
        $setting = BankQrSetting::query()->sole();
        $record = BankQrDiagnostic::query()->create([
            'company_id' => $this->company->id, 'active_company_id' => $this->company->id,
            'bank_qr_setting_id' => $setting->id, 'user_id' => $this->owner->id,
            'amount' => '1.00', 'commercial_reference' => 'D'.$this->requestKey,
            'status' => 'creating', 'expires_at' => now()->addDay(),
        ]);
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame('creating', $record->refresh()->status);
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
        Http::assertNothingSent();
    }

    public function test_pending_diagnostic_can_be_checked_without_the_legacy_marker(): void
    {
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $record->update(['active_company_id' => null]);
        $this->get(route('bank-qr-diagnostics.index'))->assertSee(route('bank-qr-diagnostics.check', $record->ulid));
        $this->mode = 'paid';
        $this->post(route('bank-qr-diagnostics.check', $record->ulid))->assertRedirect();
        $this->assertSame('paid', $record->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_commercial_reference_remains_unique_in_the_database(): void
    {
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $duplicate = $record->replicate();
        $duplicate->ulid = (string) Str::ulid();
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_image_endpoint_returns_only_the_requested_png_without_side_effects(): void
    {
        $this->originalImage = $this->validPng();
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $first = BankQrDiagnostic::query()->sole();
        $this->requestKey = (string) Str::ulid();
        $png = base64_decode($first->original_qr_image, true);
        $chunk = 'tEXt'."test\0second";
        $this->originalImage = 'data:image/png;base64,'.base64_encode(substr($png, 0, -12).pack('N', strlen($chunk) - 4).$chunk.hash('crc32b', $chunk, true).substr($png, -12));
        $this->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $second = BankQrDiagnostic::query()->whereKeyNot($first->id)->sole();
        $eventCount = DB::table('bank_qr_diagnostic_events')->count();
        Http::fake();
        $page = $this->get(route('bank-qr-diagnostics.index'))->assertOk()->assertSee('Ver Base64 original');
        $page->assertSee(route('bank-qr-diagnostics.image', $first->ulid))->assertSee(route('bank-qr-diagnostics.image', $second->ulid));
        foreach ([$first, $second] as $record) {
            $response = $this->get(route('bank-qr-diagnostics.image', $record->ulid))->assertOk()
                ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame(base64_decode(str_replace('data:image/png;base64,', '', $record->original_qr_image), true), $response->getContent());
        }
        $this->assertSame($eventCount, DB::table('bank_qr_diagnostic_events')->count());
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_image_access_requires_authorized_membership_and_company(): void
    {
        $this->originalImage = $this->validPng();
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $url = route('bank-qr-diagnostics.image', $record->ulid);
        $admin = User::factory()->create();
        Membership::factory()->for($this->company)->for($admin)->create(['role' => MembershipRole::Admin]);
        $this->login($admin)->get($url)->assertOk();
        $cashier = User::factory()->create();
        Membership::factory()->for($this->company)->for($cashier)->create(['role' => MembershipRole::Cashier]);
        $this->login($cashier)->get($url)->assertForbidden();
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($other)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id])->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect();
    }

    public function test_missing_invalid_or_oversized_images_are_not_served(): void
    {
        $this->mode = 'timeout';
        $this->login($this->owner)->post(route('bank-qr-diagnostics.store'), $this->payload())->assertRedirect();
        $record = BankQrDiagnostic::query()->sole();
        $url = route('bank-qr-diagnostics.image', $record->ulid);
        $this->get($url)->assertNotFound();
        $this->get(route('bank-qr-diagnostics.index'))->assertSee('La imagen QR y el Base64 original no están disponibles');
        foreach (['invalid!', base64_encode('not a PNG'), base64_encode(substr(base64_decode($this->validPng(), true), 0, -12)), str_repeat('A', 2000004)] as $invalid) {
            $record->update(['original_qr_image' => $invalid]);
            $this->get($url)->assertNotFound();
            $this->get(route('bank-qr-diagnostics.index'))->assertSee('La imagen QR PNG no está disponible o no es válida.');
        }
    }

    private function validPng(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF/8AAAAASUVORK5CYII=', true);
        $png = substr_replace($png, hash('crc32b', substr($png, 37, 15), true), 52, 4);

        return base64_encode($png);
    }
}
