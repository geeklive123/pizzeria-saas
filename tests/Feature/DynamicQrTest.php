<?php

namespace Tests\Feature;

use App\Actions\CloseCashSessionAction;
use App\Actions\DynamicQrAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\CashSessionStatus;
use App\Enums\DynamicQrStatus;
use App\Enums\KitchenDispatchStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\TableChargeMode;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use App\Models\KitchenDispatch;
use App\Models\KitchenDispatchItem;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\BancoEconomicoGateway;
use App\Services\BankQrException;
use App\Services\ThermalPrintingService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class DynamicQrTest extends TestCase
{
    private Company $company;

    private Branch $branch;

    private User $user;

    private CashSession $session;

    private Order $order;

    private BankQrSetting $setting;

    private array $bankStatus = ['responseCode' => 0, 'statusQrCode' => 0];

    private int $generations = 0;

    private int $authentications = 0;

    private string $failure = '';

    private bool $cancelConfirmed = true;

    private ?string $rawStatus = null;

    private ?string $rawGeneration = null;

    private ?string $rawPaidList = null;

    private int $generationHttpStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:'
            || ! empty(config('database.connections.sqlite.url'))) {
            throw new \RuntimeException('Esta prueba exige configuración SQLite en memoria.');
        }
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
            || DB::selectOne('PRAGMA database_list')->file !== '') {
            throw new \RuntimeException('Esta prueba exige SQLite en memoria.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        config(['baneco.enabled' => true, 'baneco.webhook_enabled' => true]);
        Http::preventStrayRequests();
        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->for($this->company)->create();
        $this->user = User::factory()->create();
        Membership::factory()->for($this->company)->for($this->user)->owner()->create();
        $register = CashRegister::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Principal', 'is_active' => true]);
        $this->session = app(OpenCashSessionAction::class)->execute($register, '0.00', $this->user);
        $table = RestaurantTable::factory()->for($this->branch)->create(['company_id' => $this->company->id]);
        $this->order = Order::factory()->for($this->branch)->create([
            'company_id' => $this->company->id, 'created_by' => $this->user->id, 'type' => OrderType::DineIn,
            'restaurant_table_id' => $table->id, 'active_restaurant_table_id' => $table->id,
            'status' => OrderStatus::ReadyForPayment, 'subtotal' => '100.00', 'total' => '100.00',
        ]);
        OrderItem::factory()->for($this->order)->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => OrderItemStatus::Served, 'line_total' => '100.00', 'unit_price' => '100.00', 'created_by' => $this->user->id]);
        $this->setting = BankQrSetting::query()->create([
            'company_id' => $this->company->id, 'active_scope' => $this->company->id.':company',
            'credentials' => ['username' => 'test-user', 'password' => 'test-password', 'aes_key' => 'test-aes', 'account_credit' => 'test-account'],
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'authentication/encrypt')) {
                return Http::response('"  encrypted-value  "');
            }
            if (str_contains($request->url(), 'authentication/authenticate')) {
                $this->authentications++;

                return $this->failure === 'auth' || ($this->failure === 'reauth_after_generation' && $this->authentications > 1) ? Http::response(['responseCode' => 5], 401) : Http::response(['responseCode' => 0, 'token' => 'test-token-'.$this->authentications]);
            }
            if (str_contains($request->url(), 'generateQR')) {
                $this->generations++;
                if ($this->failure === 'reauth_after_generation') {
                    return Http::response([], 401);
                }
                if ($this->rawGeneration !== null) {
                    return Http::response($this->rawGeneration, $this->generationHttpStatus);
                }
                if ($this->failure === 'generate_timeout') {
                    return Http::failedConnection();
                }

                return Http::response(['responseCode' => 0, 'qrId' => 'bank-'.$this->generations, 'qrImage' => $this->failure === 'image' ? 'bad-image' : 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
            }
            if (str_contains($request->url(), 'paidQR')) {
                return Http::response($this->rawPaidList);
            }
            if (str_contains($request->url(), 'cancelQR')) {
                if ($this->failure === 'cancel_timeout') {
                    return Http::failedConnection();
                }
                if ($this->cancelConfirmed) {
                    $this->bankStatus = ['responseCode' => 0, 'statusQrCode' => 9];
                }

                return Http::response(['responseCode' => 0]);
            }
            if (str_contains($request->url(), 'statusQR')) {
                if ($this->failure === 'status_timeout') {
                    return Http::failedConnection();
                }
                if ($this->failure === 'renew' && $request->header('Authorization')[0] === 'Bearer test-token-1') {
                    return Http::response([], 401);
                }

                return Http::response($this->rawStatus ?? $this->bankStatus);
            }
            $this->fail('Unexpected HTTP request');
        });
    }

    private function generate(string $cash = '0.00', ?KitchenDispatch $dispatch = null, string $key = 'request-one'): DynamicQrIntent
    {
        return app(DynamicQrAction::class)->generate($this->order, $this->session, $this->user, $key, $dispatch, $cash);
    }

    private function paid(string $amount = '100.00', string $currency = 'BOB', string $qr = 'bank-1'): void
    {
        $this->bankStatus = ['responseCode' => 0, 'statusQrCode' => 1, 'payment' => ['qrId' => $qr, 'amount' => $amount, 'currency' => $currency, 'transactionId' => 'bank-transaction-not-order-id']];
    }

    private function check(DynamicQrIntent $intent, bool $cancel = false): DynamicQrIntent
    {
        $this->travel(7)->seconds();

        return app(DynamicQrAction::class)->check($intent, $cancel);
    }

    private function login(): static
    {
        return $this->actingAs($this->user)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
    }

    public function test_history_matches_a_confirmed_payment_without_changing_financial_records(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $date = now()->timezone('America/La_Paz')->toDateString();
        $this->rawPaidList = json_encode(['responseCode' => 0, 'paymentList' => [[
            'qrId' => 'bank-1', 'transactionId' => 'bank-transaction-not-order-id', 'currency' => 'BOB', 'amount' => '100.00',
            'paymentDate' => $date, 'paymentTime' => '12:34:56',
        ]]], JSON_THROW_ON_ERROR);
        $this->login()->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertOk()->assertSee('Sin consulta bancaria aplicable');
        $before = Payment::query()->sole()->getRawOriginal();
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertRedirect();
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertOk()->assertSee('Coincide con API')->assertSee('Abono en cuenta: no verificado')->assertSee('bank-1')->assertSee('QR PAGADO');
        $this->assertSame($before, Payment::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_history_rejects_mismatch_duplicates_and_never_infers_absence_on_failure(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $date = now()->timezone('America/La_Paz')->toDateString();
        $entry = ['qrId' => 'bank-1', 'transactionId' => 'bank-transaction-not-order-id', 'currency' => 'BOB', 'amount' => '99.00', 'paymentDate' => $date, 'paymentTime' => '12:00:00'];
        $this->login();
        $this->rawPaidList = json_encode(['responseCode' => 0, 'paymentList' => [$entry]], JSON_THROW_ON_ERROR);
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertRedirect();
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertSee('Diferencia de importe o identificador');
        $this->rawPaidList = json_encode(['responseCode' => 0, 'paymentList' => [$entry, $entry]], JSON_THROW_ON_ERROR);
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertRedirect();
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertSee('Requiere revisión');
        $this->rawPaidList = '{"responseCode":0,"paymentList":[{"qrId":"bank-1"}]}';
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertSessionHasErrors('bank');
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertSee('Sin consulta bancaria aplicable');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_history_restricts_access_and_does_not_contact_bank_on_load(): void
    {
        $this->login()->get(route('bank-qr-history.index'))->assertOk();
        Http::assertNothingSent();
        $kitchen = User::factory()->create();
        Membership::factory()->for($this->company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingAs($kitchen)->get(route('bank-qr-history.index'))->assertForbidden();
        $this->post(route('bank-qr-history.consult'), ['date' => now()->toDateString(), 'setting_id' => $this->setting->id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_distinguishes_empty_and_unmatched_bank_lists(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $date = now()->timezone('America/La_Paz')->toDateString();
        $this->login();
        $this->rawPaidList = '{"responseCode":0,"paymentList":[]}';
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertRedirect();
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertSee('Pago local sin evidencia bancaria suficiente');
        $entry = ['qrId' => 'unmatched-qr', 'transactionId' => 'transaction-2', 'currency' => 'BOB', 'amount' => '8.00', 'paymentDate' => $date, 'paymentTime' => '12:00:00'];
        $this->rawPaidList = json_encode(['responseCode' => 0, 'paymentList' => [$entry]], JSON_THROW_ON_ERROR);
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertRedirect();
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertSee('unmatched-qr')->assertSee('Pago bancario sin registro local');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_history_does_not_match_a_qr_id_shared_with_a_diagnostic(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        BankQrDiagnostic::query()->create([
            'company_id' => $this->company->id, 'bank_qr_setting_id' => $this->setting->id, 'user_id' => $this->user->id,
            'amount' => '100.00', 'commercial_reference' => 'diagnostic-shared', 'bank_qr_id' => 'bank-1',
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
        $date = now()->timezone('America/La_Paz')->toDateString();
        $entry = ['qrId' => 'bank-1', 'transactionId' => 'bank-transaction-not-order-id', 'currency' => 'BOB', 'amount' => '100.00', 'paymentDate' => $date, 'paymentTime' => '12:00:00'];
        $this->rawPaidList = json_encode(['responseCode' => 0, 'paymentList' => [$entry]], JSON_THROW_ON_ERROR);
        $this->login()->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertRedirect();
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date, 'state' => 'review']))->assertOk()->assertSee('diagnostic-shared')->assertSee($intent->commercial_reference);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_history_individual_status_is_read_only_and_isolated(): void
    {
        $intent = $this->generate();
        $url = route('bank-qr-history.status', $intent->ulid);
        $before = $intent->getRawOriginal();
        $this->login()->post($url, ['kind' => 'sale'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame($before, $intent->refresh()->getRawOriginal());
        $this->assertDatabaseCount('payments', 0);
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($other)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id])->post($url, ['kind' => 'sale'])->assertNotFound();
    }

    public function test_deposit_evidence_is_separate_auditable_and_does_not_change_payment(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $payment = Payment::query()->sole();
        $before = $payment->getRawOriginal();
        $data = ['request_key' => (string) Str::ulid(), 'deposit_date' => now()->toDateString(),
            'details' => 'Extracto bancario comprobante referencia 12345', 'confirmed' => '1'];
        $url = route('bank-qr-history.deposit', $intent->ulid);
        $this->login()->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $changed = $data;
        $changed['details'] = 'Otro comprobante bancario distinto al original';
        $this->post($url, $changed)->assertUnprocessable();
        $this->assertDatabaseCount('bank_qr_deposit_evidence', 1);
        $this->assertStringNotContainsString($data['details'], DB::table('bank_qr_deposit_evidence')->value('details'));
        $this->assertSame($before, $payment->refresh()->getRawOriginal());
        $this->get(route('bank-qr-history.index'))->assertSee('Abono verificado por administrador mediante evidencia independiente');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_deposit_evidence_denies_foreign_company_without_touching_payment_or_contacting_bank(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $payment = Payment::query()->sole();
        $before = $payment->getRawOriginal();
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($other)->for($owner)->owner()->create();
        Http::fake();
        $this->actingAs($owner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id])
            ->post(route('bank-qr-history.deposit', $intent->ulid), ['request_key' => (string) Str::ulid(),
                'deposit_date' => now()->toDateString(), 'details' => 'Extracto bancario comprobante referencia 12345', 'confirmed' => '1'])->assertNotFound();
        $this->assertDatabaseCount('bank_qr_deposit_evidence', 0);
        $this->assertSame($before, $payment->refresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_deposit_evidence_composite_foreign_key_rejects_foreign_company_and_branch(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $payment = Payment::query()->sole();
        $before = $payment->getRawOriginal();
        $other = Company::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $sameCompanyBranch = Branch::factory()->for($this->company)->create();
        foreach ([[$other->id, $otherBranch->id], [$this->company->id, $sameCompanyBranch->id]] as [$companyId, $branchId]) {
            try {
                DB::table('bank_qr_deposit_evidence')->insert(['request_key' => (string) Str::ulid(), 'company_id' => $companyId,
                    'branch_id' => $branchId, 'intent_id' => $intent->id, 'user_id' => $this->user->id,
                    'deposit_date' => now()->toDateString(), 'details' => 'fixture', 'created_at' => now()]);
                $this->fail('La FK compuesta debía impedir el ámbito incorrecto.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('FOREIGN KEY', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('bank_qr_deposit_evidence', 0);
        $this->assertSame($before, $payment->refresh()->getRawOriginal());
    }

    public function test_deposit_evidence_propagates_unexpected_database_failure(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $payment = Payment::query()->sole();
        $before = $payment->getRawOriginal();
        DB::unprepared("CREATE TEMP TRIGGER fail_deposit_evidence BEFORE INSERT ON bank_qr_deposit_evidence BEGIN SELECT RAISE(ABORT, 'forced evidence failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->login()->post(route('bank-qr-history.deposit', $intent->ulid), ['request_key' => (string) Str::ulid(),
                'deposit_date' => now()->toDateString(), 'details' => 'Extracto bancario comprobante referencia 12345', 'confirmed' => '1']);
            $this->fail('El fallo de base de datos no debe convertirse en éxito.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced evidence failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('bank_qr_deposit_evidence', 0);
        $this->assertSame($before, $payment->refresh()->getRawOriginal());
    }

    public function test_deposit_evidence_simulated_unique_race_cannot_modify_payment_or_succeed(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->check($intent);
        $payment = Payment::query()->sole();
        $before = $payment->getRawOriginal();
        // Simulates a uniqueness failure after the initial lookup; not a real concurrent race.
        DB::unprepared("CREATE TEMP TRIGGER fail_deposit_unique BEFORE INSERT ON bank_qr_deposit_evidence BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: bank_qr_deposit_evidence.request_key'); END");
        $this->withoutExceptionHandling();
        try {
            $this->login()->post(route('bank-qr-history.deposit', $intent->ulid), ['request_key' => (string) Str::ulid(),
                'deposit_date' => now()->toDateString(), 'details' => 'Extracto bancario comprobante referencia 12345', 'confirmed' => '1']);
            $this->fail('El conflicto simulado no debe convertirse en éxito.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('bank_qr_deposit_evidence', 0);
        $this->assertSame($before, $payment->refresh()->getRawOriginal());
    }

    public function test_deposit_evidence_rejects_pending_and_unauthorized_users(): void
    {
        $intent = $this->generate();
        $data = ['request_key' => (string) Str::ulid(), 'deposit_date' => now()->toDateString(),
            'details' => 'Extracto bancario comprobante referencia 12345', 'confirmed' => '1'];
        $url = route('bank-qr-history.deposit', $intent->ulid);
        $this->login()->post($url, $data)->assertUnprocessable();
        $kitchen = User::factory()->create();
        Membership::factory()->for($this->company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->actingAs($kitchen)->post($url, $data)->assertForbidden();
        $this->assertDatabaseCount('bank_qr_deposit_evidence', 0);
    }

    public function test_history_rejects_foreign_setting_and_failed_authentication(): void
    {
        $other = Company::factory()->create();
        $foreign = BankQrSetting::query()->create(['company_id' => $other->id, 'active_scope' => $other->id.':company', 'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
        $date = now()->timezone('America/La_Paz')->toDateString();
        $this->login()->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $foreign->id])->assertNotFound();
        Http::assertNothingSent();
        $this->failure = 'auth';
        $this->post(route('bank-qr-history.consult'), ['date' => $date, 'setting_id' => $this->setting->id])->assertSessionHasErrors('bank');
        $this->get(route('bank-qr-history.index', ['from' => $date, 'to' => $date]))->assertDontSee('Consulta temporal:');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manual_check_marks_stale_creating_without_bank_id_uncertain_and_keeps_cash(): void
    {
        $intent = $this->generate('30.00');
        $intent->update(['status' => DynamicQrStatus::Creating, 'bank_qr_id' => null, 'created_at' => now()->subMinutes(5)]);
        $result = app(DynamicQrAction::class)->check($intent);
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $result->status);
        $this->assertSame($this->order->id, $result->active_order_id);
        $this->assertSame('30.00', Payment::query()->sole()->amount);
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertSame(1, $this->generations);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'statusQR'));
    }

    public function test_manual_check_queries_known_bank_id_without_regenerating_stale_creating_intent(): void
    {
        $intent = $this->generate();
        $intent->update(['status' => DynamicQrStatus::Creating, 'created_at' => now()->subMinutes(5)]);
        $result = app(DynamicQrAction::class)->check($intent);
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $result->status);
        $this->assertSame(1, $this->generations);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'statusQR/bank-1'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_generation_inside_outer_transaction_is_rejected_before_bank_or_financial_writes(): void
    {
        try {
            DB::transaction(fn () => $this->generate('30.00'));
            $this->fail('Se esperaba el rechazo de la transacción exterior.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('transacción sin confirmar', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_historical_creating_intent_with_null_phase_is_not_reclaimed(): void
    {
        $intent = $this->generate();
        $intent->update(['generation_phase' => null, 'status' => DynamicQrStatus::Creating]);

        $again = $this->generate();
        $this->assertSame($intent->id, $again->id);
        $this->assertNull($again->generation_phase);
        $this->assertSame(1, $this->generations);
    }

    public function test_new_generation_persists_generated_phase(): void
    {
        $intent = $this->generate();
        $this->assertSame('generated', $intent->fresh()->generation_phase);
        $this->assertSame(1, $this->generations);
    }

    public function test_generation_uses_exact_fixed_single_use_amount_and_encrypted_configuration(): void
    {
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::Pending, $intent->status);
        $this->assertSame('100.00', $intent->amount);
        $this->assertStringStartsWith('data:image/png;base64,', $intent->qr_image);
        $this->assertStringNotContainsString('test-password', $this->setting->getRawOriginal('credentials'));
        $this->assertArrayNotHasKey('credentials', $this->setting->toArray());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generateQR') && str_contains($r->body(), '"amount":100.00') && $r['singleUse'] === true && $r['modifyAmount'] === false && $r['accountCredit'] === 'encrypted-value' && $r['currency'] === 'BOB');
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($intent->id, $this->generate(key: 'another-click')->id);
        $this->assertSame(1, $this->generations);
    }

    public function test_generation_request_matches_plugin_checkout_contract(): void
    {
        $this->setting->update(['credentials' => $this->setting->credentials + ['branch_code' => '12345']]);
        $intent = $this->generate();
        $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertSame(['application/json'], $request->header('Accept'));
            $this->assertSame(['application/json'], $request->header('Content-Type'));
        }
        foreach ([0 => 'test-account', 1 => 'test-password'] as $index => $plain) {
            $this->assertSame('GET', $requests[$index]->method());
            $this->assertSame('/ApiGateway/api/authentication/encrypt', parse_url($requests[$index]->url(), PHP_URL_PATH));
            parse_str(parse_url($requests[$index]->url(), PHP_URL_QUERY), $query);
            $this->assertSame(['text' => $plain, 'aesKey' => 'test-aes'], $query);
        }
        $this->assertSame('POST', $requests[2]->method());
        $this->assertSame('/ApiGateway/api/authentication/authenticate', parse_url($requests[2]->url(), PHP_URL_PATH));
        $this->assertSame(['userName' => 'test-user', 'password' => 'encrypted-value'], $requests[2]->data());
        $this->assertSame('POST', $requests[3]->method());
        $this->assertSame('/ApiGateway/api/qrsimple/generateQR', parse_url($requests[3]->url(), PHP_URL_PATH));
        $this->assertSame(['Bearer test-token-1'], $requests[3]->header('Authorization'));
        $this->assertSame([
            'transactionId' => $intent->commercial_reference, 'accountCredit' => 'encrypted-value',
            'currency' => 'BOB', 'amount' => 100.0, 'description' => 'Masa & Maña',
            'dueDate' => $intent->expires_at->timezone('America/La_Paz')->format('Y-m-d'),
            'singleUse' => true, 'modifyAmount' => false, 'branchCode' => '12345',
        ], $requests[3]->data());
        $this->assertMatchesRegularExpression('/^\d{16}$/D', $intent->commercial_reference);
        $this->assertStringContainsString('"amount":100.00', $requests[3]->body());
    }

    public function test_paid_list_is_read_only_and_preserves_exact_official_payment_fields(): void
    {
        $this->rawPaidList = '{"responseCode":0,"paymentList":[{"qrId":"bank-1","transactionId":"1236392","paymentDate":"2021-07-19T00:00:00","paymentTime":"13:34:28","currency":"BOB","amount":40.00,"senderName":"private"}]}';
        $list = app(BancoEconomicoGateway::class)->paidOn($this->setting, '20210719');
        $this->assertSame([['qrId' => 'bank-1', 'transactionId' => '1236392', 'paymentDate' => '2021-07-19T00:00:00', 'paymentTime' => '13:34:28', 'currency' => 'BOB', 'amount' => '40.00']], $list);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/qrsimple/v2/paidQR/20210719') && $r->header('Authorization') === ['Bearer test-token-1']);
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paid_list_rejects_invalid_date_and_ambiguous_payment_data(): void
    {
        $gateway = app(BancoEconomicoGateway::class);
        try {
            $gateway->paidOn($this->setting, '20210230');
            $this->fail('Invalid date must be rejected.');
        } catch (BankQrException $exception) {
            $this->assertSame('invalid_paid_date', $exception->issue);
        }
        Http::assertNothingSent();
        $this->rawPaidList = '{"responseCode":0,"paymentList":[{"qrId":"bank-1","amount":"40.00"}]}';
        try {
            $gateway->paidOn($this->setting, '20210719');
            $this->fail('Incomplete payment must be rejected.');
        } catch (BankQrException $exception) {
            $this->assertSame('invalid_paid_list', $exception->issue);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_conflicting_status_spellings_never_cancel_or_register_payment(): void
    {
        $intent = $this->generate();
        $this->bankStatus = ['responseCode' => 0, 'statusQrCode' => 0, 'statusQRCode' => 1];
        $this->assertSame(DynamicQrStatus::Pending, $this->check($intent, true)->status);
        $this->assertSame('conflicting_bank_status', $intent->refresh()->issue_code);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_official_status_spelling_and_single_payment_list_confirm_exact_payment(): void
    {
        $intent = $this->generate();
        $this->rawStatus = '{"responseCode":0,"statusQRCode":1,"payment":[{"qrId":"bank-1","transactionId":"bank-payment","currency":"BOB","amount":100.00}]}';
        $this->assertSame(DynamicQrStatus::Paid, $this->check($intent)->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_invalid_generation_response_is_diagnosed_without_logging_sensitive_values(): void
    {
        Log::spy();
        $this->rawGeneration = '{"responseCode":"secret-password","qrId":"sensitive-id","token":"secret-token"}';
        $intent = $this->generate();

        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('bank_invalid_response', $intent->issue_code);
        $this->assertNull($intent->bank_qr_id);
        $this->assertDatabaseCount('payments', 0);
        Log::shouldHaveReceived('warning')->with('baneco_response_diagnostic', [
            'operation' => 'generate', 'http_status' => 200, 'bank_response_code' => null,
            'json_valid' => true, 'generation_fields_present' => ['qrId' => true, 'qrImage' => false],
            'error_code' => 'bank_invalid_response',
        ])->once();
    }

    public function test_malformed_generation_json_remains_uncertain_and_is_diagnosed(): void
    {
        Log::spy();
        $this->rawGeneration = '<html>private bank error</html>';
        $intent = $this->generate();

        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertDatabaseCount('payments', 0);
        Log::shouldHaveReceived('warning')->with('baneco_response_diagnostic', [
            'operation' => 'generate', 'http_status' => 200, 'bank_response_code' => null,
            'json_valid' => false, 'generation_fields_present' => null,
            'error_code' => 'bank_invalid_response',
        ])->once();
    }

    public function test_large_json_image_does_not_hide_a_received_qr_id(): void
    {
        $this->rawGeneration = json_encode(['responseCode' => 0, 'qrId' => 'bank-large', 'qrImage' => str_repeat('A', 100000)], JSON_THROW_ON_ERROR);
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('bank-large', $intent->bank_qr_id);
        $this->assertSame('invalid_qr_image', $intent->issue_code);
    }

    public function test_large_valid_image_and_escaped_strings_decode_successfully(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF/8AAAAASUVORK5CYII=', true);
        $this->rawGeneration = json_encode(['responseCode' => 0, 'qrId' => 'bank-large', 'qrImage' => base64_encode($png.str_repeat('x', 100000)), 'message' => 'quoted "123" and \\ 456'], JSON_THROW_ON_ERROR);
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::Pending, $intent->status);
        $this->assertSame('bank-large', $intent->bank_qr_id);
        $this->assertStringStartsWith('data:image/png;base64,', $intent->qr_image);
    }

    public function test_nested_generation_response_is_not_assumed_to_be_official_contract(): void
    {
        Log::spy();
        $this->rawGeneration = '{"responseCode":0,"data":{"qrId":"private-id","qrImage":"private-image"}}';
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('invalid_generation_response', $intent->issue_code);
        $this->assertNull($intent->bank_qr_id);
        Log::shouldHaveReceived('info')->with('baneco_response_shape', [
            'operation' => 'generate', 'http_status' => 200, 'bank_response_code' => '0',
            'fields' => ['responseCode' => 'number', 'data' => 'object', 'data.fields' => ['qrId' => 'string', 'qrImage' => 'string']],
            'generation_fields_present' => ['qrId' => false, 'qrImage' => false],
        ])->once();
    }

    public function test_generation_preserves_qr_id_when_image_has_wrong_type(): void
    {
        $this->rawGeneration = '{"responseCode":0,"qrId":"bank-typed","qrImage":{"private":"value"}}';
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('bank-typed', $intent->bank_qr_id);
        $this->assertSame('invalid_qr_image', $intent->issue_code);
    }

    public function test_generation_shape_logs_only_allowlisted_field_names_and_types(): void
    {
        Log::spy();
        $this->rawGeneration = '{"responseCode":0,"qrId":null,"qrImage":[],"private-secret-key":"private-value","result":{"qrId":42,"private-secret":"private"}}';
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        Log::shouldHaveReceived('info')->with('baneco_response_shape', [
            'operation' => 'generate', 'http_status' => 200, 'bank_response_code' => '0',
            'fields' => ['responseCode' => 'number', 'qrId' => 'null', 'qrImage' => 'array', 'result' => 'object', 'result.fields' => ['qrId' => 'number']],
            'generation_fields_present' => ['qrId' => true, 'qrImage' => true],
        ])->once();
    }

    public function test_generation_http_error_logs_only_safe_response_metadata(): void
    {
        Log::spy();
        $this->rawGeneration = '{"responseCode":42,"qrImage":"private-image"}';
        $this->generationHttpStatus = 503;
        $intent = $this->generate();

        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        Log::shouldHaveReceived('warning')->with('baneco_response_diagnostic', [
            'operation' => 'generate', 'http_status' => 503, 'bank_response_code' => '42',
            'json_valid' => true, 'generation_fields_present' => ['qrId' => false, 'qrImage' => true],
            'error_code' => 'bank_http_error',
        ])->once();
    }

    public function test_nonzero_bank_response_code_is_diagnosed_without_creating_a_payment(): void
    {
        Log::spy();
        $this->rawGeneration = '{"responseCode":17,"message":"private detail","qrId":"bank-1"}';
        $intent = $this->generate();

        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('bank_response_17', $intent->issue_code);
        $this->assertDatabaseCount('payments', 0);
        Log::shouldHaveReceived('warning')->with('baneco_response_diagnostic', [
            'operation' => 'generate', 'http_status' => 200, 'bank_response_code' => '17',
            'json_valid' => true, 'generation_fields_present' => ['qrId' => true, 'qrImage' => false],
            'error_code' => 'bank_response_17',
        ])->once();
    }

    public function test_missing_generation_fields_are_diagnosed_without_releasing_uncertain_intent(): void
    {
        Log::spy();
        $this->rawGeneration = '{"responseCode":"0"}';
        $intent = $this->generate();

        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('invalid_generation_response', $intent->issue_code);
        $this->assertNotNull($intent->active_order_id);
        $this->assertDatabaseCount('payments', 0);
        Log::shouldHaveReceived('warning')->with('baneco_response_diagnostic', [
            'operation' => 'generate', 'http_status' => 200, 'bank_response_code' => '0',
            'json_valid' => true, 'generation_fields_present' => ['qrId' => false, 'qrImage' => false],
            'error_code' => 'invalid_generation_fields',
        ])->once();
    }

    public function test_success_response_code_string_and_alias_are_accepted(): void
    {
        $this->rawGeneration = '{"response_code":"0","qrId":"bank-1","qrImage":"iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF/8AAAAASUVORK5CYII="}';
        $this->assertSame(DynamicQrStatus::Pending, $this->generate()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_authentication_failure_preserves_cash_and_safe_retry_does_not_repeat_it(): void
    {
        $this->failure = 'auth';
        $intent = $this->generate('30.00');
        $this->assertSame(DynamicQrStatus::Failed, $intent->status);
        $this->assertSame('70.00', $intent->amount);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($intent->id, $this->generate('30.00')->id);
        $this->check($intent, true);
        $this->failure = '';
        $retry = $this->generate(key: 'safe-retry');
        $this->assertSame('70.00', $retry->amount);
        $this->paid('70.00');
        $this->assertSame(DynamicQrStatus::Paid, $this->check($retry)->status);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertSame('30.00', Payment::query()->where('method', PaymentMethod::Cash)->sole()->amount);
    }

    public function test_unauthorized_generation_is_uncertain_and_never_replayed(): void
    {
        $this->failure = 'reauth_after_generation';
        $intent = $this->generate('30.00');
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('70.00', $intent->amount);
        $this->assertSame($intent->id, $this->generate('30.00')->id);
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $this->check($intent, true)->status);
        $this->assertSame(1, $this->generations);
        $this->assertSame(1, $this->authentications);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(PaymentMethod::Cash, Payment::query()->sole()->method);
        $this->assertNotNull($intent->refresh()->active_order_id);
    }

    public function test_pending_and_unknown_bank_states_never_create_payments(): void
    {
        $intent = $this->generate();
        foreach ([0, 2, 3, 8] as $status) {
            $this->bankStatus['statusQrCode'] = $status;
            $this->assertSame($status === 0 ? DynamicQrStatus::Pending : DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_scheduler_recovers_after_timeout_without_browser_or_duplicate_payment(): void
    {
        $intent = $this->generate();
        $this->failure = 'status_timeout';
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertSame('bank_unreachable', $intent->refresh()->issue_code);
        $this->assertDatabaseCount('payments', 0);
        $this->failure = '';
        $this->paid();
        $this->travel(61)->seconds();
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertSame(DynamicQrStatus::Paid, $intent->refresh()->status);
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_status_queries_write_only_sanitized_result_metadata(): void
    {
        $intent = $this->generate();
        Log::spy();
        $this->check($intent);
        Log::shouldHaveReceived('info')->with('baneco_status_check', [
            'intent' => $intent->ulid, 'company_id' => $this->company->id, 'result' => '0',
        ])->once();
        $this->failure = 'status_timeout';
        $this->check($intent);
        Log::shouldHaveReceived('warning')->with('baneco_status_check', [
            'intent' => $intent->ulid, 'company_id' => $this->company->id, 'result' => 'error', 'issue' => 'bank_unreachable',
        ])->once();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_scheduler_does_not_interrupt_locked_generation_or_consult_it(): void
    {
        $intent = $this->generate();
        $intent->update(['status' => DynamicQrStatus::Creating, 'created_at' => now()->subMinutes(5)]);
        $lock = Cache::lock('baneco:intent:'.$intent->id, 120);
        $this->assertTrue($lock->get());
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertSame(DynamicQrStatus::Creating, $intent->refresh()->status);
        $lock->release();
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unknown_status_never_requests_cancellation(): void
    {
        $intent = $this->generate();
        $this->bankStatus['statusQrCode'] = 8;
        $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent, true)->status);
        $this->assertSame('unknown_bank_status', $intent->refresh()->issue_code);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cancelQR'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_at_end_paid_confirmation_closes_table_and_is_idempotent_without_auth_session(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->assertGuest();
        $this->assertSame(DynamicQrStatus::Paid, $this->check($intent)->status);
        $this->check($intent);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertDatabaseCount('print_attempts', 1);
        $this->assertSame(OrderStatus::Paid, $this->order->refresh()->status);
        $this->assertNull($this->order->active_restaurant_table_id);
    }

    public function test_per_batch_uses_only_batch_balance_and_releases_preparation_after_confirmation(): void
    {
        $this->order->update(['charge_mode' => TableChargeMode::PerBatch, 'status' => OrderStatus::Open]);
        $dispatch = KitchenDispatch::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'order_id' => $this->order->id, 'sequence_number' => 1, 'status' => KitchenDispatchStatus::AwaitingPayment, 'dispatched_at' => now(), 'dispatched_by' => $this->user->id, 'total' => '40.00', 'gross_subtotal' => '40.00']);
        $item = $this->order->items()->first();
        $item->update(['status' => OrderItemStatus::PendingPayment, 'requires_preparation' => true]);
        KitchenDispatchItem::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'kitchen_dispatch_id' => $dispatch->id, 'order_item_id' => $item->id, 'gross_total' => '40.00', 'net_total' => '40.00']);
        $intent = $this->generate('10.00', $dispatch);
        $this->assertSame('30.00', $intent->amount);
        $this->assertSame(OrderItemStatus::PendingPayment, $item->refresh()->status);
        $this->assertSame(KitchenDispatchStatus::AwaitingPayment, $dispatch->refresh()->status);
        $this->paid('30.00');
        $this->check($intent);
        $this->assertSame(KitchenDispatchStatus::Settled, $dispatch->refresh()->status);
        $this->assertSame(OrderItemStatus::Sent, $item->refresh()->status);
        $this->assertSame(OrderStatus::Open, $this->order->refresh()->status);
        $this->assertNotNull($this->order->active_restaurant_table_id);
        $this->assertDatabaseCount('print_attempts', 1);
        $this->login()->get(route('orders.checkout', ['order' => $this->order->ulid, 'dispatch' => $dispatch->ulid]))->assertOk();
    }

    public function test_generation_timeout_is_not_retried_or_treated_as_cancelled(): void
    {
        $this->failure = 'generate_timeout';
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame($intent->id, $this->generate(key: 'retry')->id);
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $this->check($intent, true)->status);
        $this->assertSame(1, $this->generations);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_uncertain_sale_allows_independent_diagnostic(): void
    {
        $this->failure = 'generate_timeout';
        $intent = $this->generate();
        config(['baneco.enabled' => false, 'baneco.diagnostics_enabled' => true]);
        $this->login()->get(route('bank-qr-diagnostics.index'))->assertOk()->assertSee($intent->commercial_reference)
            ->assertSee('Puedes generar una prueba nueva');
        $this->post(route('bank-qr-diagnostics.store'), ['amount' => '1.00', 'request_key' => (string) Str::ulid(), 'confirm_generation' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_qr_diagnostics', 1);
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->refresh()->status);
        $this->assertSame(2, $this->generations);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_uncertain_sale_allows_generation_for_a_different_order(): void
    {
        $this->failure = 'generate_timeout';
        $intent = $this->generate();
        $otherOrder = Order::factory()->for($this->branch)->create([
            'company_id' => $this->company->id, 'created_by' => $this->user->id,
            'type' => OrderType::Takeaway, 'status' => OrderStatus::ReadyForPayment,
            'subtotal' => '20.00', 'total' => '20.00',
        ]);
        $second = app(DynamicQrAction::class)->generate($otherOrder, $this->session, $this->user, 'other-order');
        $this->assertNotSame($intent->commercial_reference, $second->commercial_reference);
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->refresh()->status);
        $this->assertSame(2, $this->generations);
        $this->assertDatabaseCount('dynamic_qr_intents', 2);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_uncertain_diagnostic_does_not_block_independent_order_generation(): void
    {
        BankQrDiagnostic::query()->create([
            'company_id' => $this->company->id, 'bank_qr_setting_id' => $this->setting->id,
            'user_id' => $this->user->id, 'active_company_id' => null,
            'amount' => '1.00', 'commercial_reference' => 'diagnostic-unknown',
            'status' => 'generation_unknown', 'expires_at' => now()->addDay(),
        ]);
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::Pending, $intent->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dynamic_qr_intents', 1);
    }

    public function test_invalid_image_keeps_bank_id_for_reconciliation_and_does_not_regenerate(): void
    {
        $this->failure = 'image';
        $intent = $this->generate();
        $this->assertSame(DynamicQrStatus::GenerationUnknown, $intent->status);
        $this->assertSame('bank-1', $intent->bank_qr_id);
        $this->assertNull($intent->qr_image);
        $this->assertSame($intent->id, $this->generate()->id);
    }

    public function test_status_timeout_is_recoverable_and_401_renews_cached_token(): void
    {
        $intent = $this->generate();
        $this->failure = 'status_timeout';
        $this->assertSame(DynamicQrStatus::Pending, $this->check($intent)->status);
        $this->failure = 'renew';
        $this->paid();
        $this->assertSame(DynamicQrStatus::Paid, $this->check($intent)->status);
        $this->assertSame(2, $this->authentications);
    }

    public function test_wrong_amount_currency_id_or_missing_currency_requires_reconciliation(): void
    {
        $intent = $this->generate();
        foreach ([['99.99', 'BOB', 'bank-1'], ['100.00', 'USD', 'bank-1'], ['100.00', 'BOB', 'other'], ['100.00', '', 'bank-1']] as [$amount, $currency, $qr]) {
            $this->paid($amount, $currency, $qr);
            $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_cancel_requires_remote_status_nine_and_regeneration_has_new_reference(): void
    {
        $intent = $this->generate();
        $this->cancelConfirmed = false;
        $this->assertSame(DynamicQrStatus::Pending, $this->check($intent, true)->status);
        $this->assertSame('cancellation_unconfirmed', $intent->refresh()->issue_code);
        $this->cancelConfirmed = true;
        $this->assertSame(DynamicQrStatus::Cancelled, $this->check($intent, true)->status);
        $next = $this->generate(key: 'new');
        $this->assertNotSame($intent->commercial_reference, $next->commercial_reference);
        $this->assertNotSame($intent->bank_qr_id, $next->bank_qr_id);
    }

    public function test_payment_wins_over_cancel_and_late_payment_is_quarantined(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->assertSame(DynamicQrStatus::Paid, $this->check($intent, true)->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_paid_after_confirmed_cancellation_requires_reconciliation(): void
    {
        $intent = $this->generate();
        $this->check($intent, true);
        $this->paid();
        $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
        $this->assertSame('late_payment', $intent->refresh()->issue_code);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_closed_cash_session_never_receives_an_asynchronous_payment(): void
    {
        $intent = $this->generate();
        $this->session->update(['status' => CashSessionStatus::Closed, 'active_cash_register_id' => null, 'closed_at' => now()]);
        $this->paid();
        $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
        $this->assertSame('cash_session_closed', $intent->refresh()->issue_code);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_close_is_blocked_by_an_active_qr(): void
    {
        $this->generate();
        $this->expectException(DomainException::class);
        app(CloseCashSessionAction::class)->execute($this->session, '0.00', $this->user);
    }

    public function test_manual_cash_cannot_overpay_an_active_qr(): void
    {
        $this->generate();
        $this->expectException(DomainException::class);
        app(RegisterPaymentAction::class)->execute($this->order, $this->session, PaymentMethod::Cash, '100.00', $this->user, 'manual');
    }

    public function test_manual_qr_cannot_be_marked_complete_when_bank_feature_is_enabled(): void
    {
        $this->login()->post(route('orders.payments.store', $this->order->ulid), ['method' => 'qr', 'amount' => '100.00', 'idempotency_key' => 'forged'])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_bank_status_alone_cannot_authorize_a_payment(): void
    {
        $intent = $this->generate();
        $intent->update(['bank_status' => '1']);

        try {
            app(RegisterPaymentAction::class)->execute(
                $this->order, $this->session, PaymentMethod::Qr, '100.00', $this->user,
                'forged-status', bankIntent: $intent,
            );
            $this->fail('A bank status without matching verified payment details must be rejected.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(OrderStatus::ReadyForPayment, $this->order->refresh()->status);
        }
    }

    public function test_disabled_bank_rejects_direct_qr_and_mixed_payments_and_generation(): void
    {
        config(['baneco.enabled' => false]);
        $this->login()->get(route('orders.checkout', $this->order->ulid))
            ->assertOk()->assertSee('Banco Económico no habilitado')->assertDontSee('name="method" value="qr"', false);
        $this->post(route('orders.payments.store', $this->order->ulid), [
            'method' => 'qr', 'amount' => '100.00', 'idempotency_key' => 'manual-disabled',
        ])->assertSessionHasErrors('payment');
        $this->post(route('orders.payments.store', $this->order->ulid), [
            'method' => 'mixed', 'cash_amount' => '30.00', 'idempotency_key' => 'mixed-disabled',
        ])->assertSessionHasErrors('payment');
        $this->postJson(route('orders.dynamic-qr.store', $this->order->ulid), [
            'idempotency_key' => 'disabled-generation', 'cash_amount' => '30.00',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dynamic_qr_intents', 0);
        $this->assertSame(OrderStatus::ReadyForPayment, $this->order->refresh()->status);
        $this->assertNotNull($this->order->active_restaurant_table_id);
        Http::assertNothingSent();
    }

    public function test_callback_and_polling_share_lock_and_payment_idempotency(): void
    {
        $intent = $this->generate();
        $this->paid();
        $lock = Cache::lock('baneco:intent:'.$intent->id, 120);
        $lock->get();
        $this->postJson(route('baneco.webhook'), ['payment' => ['qrId' => 'bank-1', 'amount' => '99999.00']])->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $lock->release();
        $this->check($intent);
        $this->postJson(route('baneco.webhook'), ['payment' => ['qrId' => 'bank-1']])->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('print_attempts', 1);
    }

    public function test_webhook_is_a_signal_only_and_strictly_validated(): void
    {
        $this->generate();
        $this->postJson(route('baneco.webhook'), ['payment' => ['qrId' => 'bank-1', 'statusQrCode' => 1, 'amount' => '100.00']])->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->postJson(route('baneco.webhook'), ['payment' => ['qrId' => '../attack']])->assertUnprocessable();
        config(['baneco.webhook_enabled' => false]);
        $this->postJson(route('baneco.webhook'), ['payment' => ['qrId' => 'bank-1']])->assertNotFound();
    }

    public function test_scheduler_confirms_without_webhook_and_print_failure_never_reverses_payment(): void
    {
        $intent = $this->generate();
        $this->paid();
        $this->mock(ThermalPrintingService::class)->shouldReceive('ticket')->once()->andThrow(new \RuntimeException('printer unavailable'));
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertSame(DynamicQrStatus::Paid, $intent->refresh()->status);
        $this->assertNull($intent->ticket_enqueued_at);
        $this->assertDatabaseCount('payments', 1);
        $this->app->forgetInstance(ThermalPrintingService::class);
        $this->artisan('baneco:reconcile', ['--inline' => true])->assertSuccessful();
        $this->assertDatabaseCount('print_attempts', 1);
        $this->assertNotNull($intent->refresh()->ticket_enqueued_at);
    }

    public function test_cancelled_order_or_expired_qr_is_not_paid_automatically(): void
    {
        $intent = $this->generate();
        $this->order->update(['status' => OrderStatus::Cancelled]);
        $this->paid();
        $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_expiry_does_not_release_remote_qr_or_permit_another_generation(): void
    {
        $intent = $this->generate();
        $this->travel(3)->days();
        $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
        $this->assertSame($intent->id, $this->generate(key: 'expired-retry')->id);
        $this->paid();
        $this->check($intent);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_ui_integrates_bank_and_endpoint_ignores_client_amount(): void
    {
        $this->login()->get(route('orders.checkout', $this->order->ulid))->assertOk()->assertSee('Banco Económico')->assertSee('data-dynamic-qr', false)->assertDontSee('Registrar pago QR');
        $this->get(route('orders.show', $this->order->ulid))->assertOk()->assertSee('data-dynamic-qr', false);
        $response = $this->postJson(route('orders.dynamic-qr.store', $this->order->ulid), ['idempotency_key' => 'http-create', 'amount' => '0.01', 'currency' => 'USD'])->assertOk()->assertJsonPath('intent.amount', '100.00')->assertJsonPath('intent.currency', 'BOB')->assertJsonPath('intent.status', 'pending');
        $this->assertSame(route('orders.dynamic-qr.image', [$this->order->ulid, DynamicQrIntent::query()->sole()->ulid]), $response->json('intent.image_url'));
        $this->assertSame('bank-1', $response->json('intent.bank_qr_id'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertNotSame(OrderStatus::Paid, $this->order->refresh()->status);
        $this->getJson(route('orders.dynamic-qr.show', $this->order->ulid))->assertOk()->assertJsonPath('intent.status', 'pending');
    }

    public function test_qr_checkout_uses_actual_forty_boliviano_balance_without_recording_cash(): void
    {
        $this->order->update(['total' => '40.00']);
        $response = $this->login()->postJson(route('orders.dynamic-qr.store', $this->order->ulid), [
            'idempotency_key' => 'qr-forty', 'amount' => '1.00',
        ])->assertOk()->assertJsonPath('intent.amount', '40.00')->assertJsonPath('intent.status', 'pending');
        $intent = DynamicQrIntent::query()->sole();
        $this->assertSame($intent->commercial_reference, $response->json('intent.commercial_reference'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generateQR') && str_contains($request->body(), '"amount":40.00'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertNotSame(OrderStatus::Paid, $this->order->refresh()->status);
    }

    public function test_mixed_checkout_registers_cash_once_and_recovers_before_bank_confirmation(): void
    {
        $this->order->update(['total' => '80.00']);
        $url = route('orders.dynamic-qr.store', $this->order->ulid);
        $data = ['idempotency_key' => 'mixed-eighty', 'cash_amount' => '30.00', 'received_amount' => '40.00'];
        $this->login()->postJson($url, $data)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $data['confirm_cash'] = true;
        $this->postJson($url, $data)->assertOk()->assertJsonPath('intent.amount', '50.00')
            ->assertJsonPath('summary.cash_paid', '30.00')->assertJsonPath('summary.paid', '30.00')->assertJsonPath('summary.balance', '50.00');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('intent.amount', '50.00');
        $this->getJson(route('orders.dynamic-qr.show', $this->order->ulid))->assertOk()
            ->assertJsonPath('summary.cash_paid', '30.00')->assertJsonPath('intent.status', 'pending');
        $cash = Payment::query()->sole();
        $this->assertSame('30.00', $cash->amount);
        $this->assertSame('40.00', $cash->received_amount);
        $this->assertSame('10.00', $cash->change_amount);
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertSame(1, $this->generations);
        $changed = $data;
        $changed['cash_amount'] = '20.00';
        $this->postJson($url, $changed)->assertUnprocessable();
        $this->paid('50.00');
        $this->check(DynamicQrIntent::query()->sole());
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('50.00', Payment::query()->where('method', PaymentMethod::Qr)->sole()->amount);
        $this->assertSame(OrderStatus::Paid, $this->order->refresh()->status);
    }

    public function test_mixed_timeout_keeps_cash_and_refuses_a_second_cash_attempt(): void
    {
        $this->order->update(['total' => '80.00']);
        $this->failure = 'generate_timeout';
        $url = route('orders.dynamic-qr.store', $this->order->ulid);
        $data = ['idempotency_key' => 'mixed-timeout', 'cash_amount' => '30.00', 'received_amount' => '30.00', 'confirm_cash' => true];
        $this->login()->postJson($url, $data)->assertOk()->assertJsonPath('intent.status', 'generation_unknown')->assertJsonPath('summary.balance', '50.00');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('intent.status', 'generation_unknown');
        $data['idempotency_key'] = 'another-attempt';
        $this->postJson($url, $data)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, $this->generations);
        $this->assertSame(OrderStatus::ReadyForPayment, $this->order->refresh()->status);
    }

    public function test_mixed_cancelled_qr_preserves_cash_and_next_qr_uses_remaining_balance(): void
    {
        $this->order->update(['total' => '80.00']);
        $url = route('orders.dynamic-qr.store', $this->order->ulid);
        $this->login()->postJson($url, ['idempotency_key' => 'mixed-cancel', 'cash_amount' => '30.00', 'received_amount' => '30.00', 'confirm_cash' => true])->assertOk();
        $intent = DynamicQrIntent::query()->sole();
        $this->assertSame(DynamicQrStatus::Cancelled, $this->check($intent, true)->status);
        $this->getJson(route('orders.dynamic-qr.show', $this->order->ulid))->assertOk()->assertJsonPath('intent', null)
            ->assertJsonPath('summary.cash_paid', '30.00')->assertJsonPath('summary.balance', '50.00');
        $this->postJson($url, ['idempotency_key' => 'new-qr-after-cancel'])->assertOk()
            ->assertJsonPath('intent.amount', '50.00')->assertJsonPath('summary.cash_paid', '30.00');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash')->count());
    }

    public function test_mixed_payment_calculates_qr_after_previous_partial_payments(): void
    {
        $this->order->update(['total' => '80.00']);
        app(RegisterPaymentAction::class)->execute($this->order, $this->session, PaymentMethod::Cash, '20.00', $this->user, 'previous-cash', '20.00');
        $this->login()->postJson(route('orders.dynamic-qr.store', $this->order->ulid), [
            'idempotency_key' => 'mixed-after-partial', 'cash_amount' => '30.00', 'received_amount' => '30.00', 'confirm_cash' => true,
        ])->assertOk()->assertJsonPath('intent.amount', '30.00')->assertJsonPath('summary.cash_paid', '50.00')
            ->assertJsonPath('summary.paid', '50.00')->assertJsonPath('summary.balance', '30.00');
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(OrderStatus::ReadyForPayment, $this->order->refresh()->status);
    }

    public function test_qr_image_endpoint_serves_original_png_only_for_authorized_order_context(): void
    {
        $intent = $this->generate();
        $url = route('orders.dynamic-qr.image', [$this->order->ulid, $intent->ulid]);
        $before = $intent->getRawOriginal();
        $this->login()->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')
            ->assertContent(base64_decode(substr($intent->qr_image, 22), true));
        $this->assertSame($before, $intent->refresh()->getRawOriginal());
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($other)->for($owner)->owner()->create();
        $this->actingAs($owner)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id])->get($url)->assertNotFound();
        $otherBranch = Branch::factory()->for($this->company)->create();
        $this->actingAs($this->user)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $otherBranch->id])->get($url)->assertNotFound();
        $kitchen = User::factory()->create();
        Membership::factory()->for($this->company)->for($kitchen)->create(['role' => MembershipRole::Kitchen]);
        $this->login();
        $this->actingAs($kitchen)->get($url)->assertForbidden();
        $this->login();
        $intent->update(['qr_image' => 'invalid']);
        $this->get($url)->assertNotFound();
    }

    public function test_expiry_stays_utc_and_unchanged_across_generation_and_pending_checks(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 22:53:10', 'UTC'));
        $intent = $this->generate();
        $expiry = $intent->expires_at;
        $this->assertSame('2026-10-30 03:59:59', $expiry->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-29 23:59:59', $expiry->timezone('America/La_Paz')->format('Y-m-d H:i:s'));
        $this->check($intent);
        $this->check($intent);
        $this->assertSame($expiry->toIso8601String(), $intent->refresh()->expires_at->toIso8601String());
        $this->assertSame(DynamicQrStatus::Pending, $intent->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_two_cashiers_reuse_the_same_pending_batch_intent(): void
    {
        $this->order->update(['charge_mode' => TableChargeMode::PerBatch, 'status' => OrderStatus::Open]);
        $dispatch = KitchenDispatch::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'order_id' => $this->order->id, 'sequence_number' => 1, 'status' => KitchenDispatchStatus::AwaitingPayment, 'dispatched_at' => now(), 'dispatched_by' => $this->user->id, 'total' => '40.00', 'gross_subtotal' => '40.00']);
        $first = $this->generate(dispatch: $dispatch);
        $other = User::factory()->create();
        Membership::factory()->for($this->company)->for($other)->owner()->create();
        $register = CashRegister::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Segunda caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $other);
        $second = app(DynamicQrAction::class)->generate($this->order, $session, $other, 'other-cashier', $dispatch);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('40.00', $second->amount);
        $this->assertSame(1, $this->generations);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(KitchenDispatchStatus::AwaitingPayment, $dispatch->refresh()->status);
    }

    public function test_tenant_and_branch_isolation_rejects_other_orders(): void
    {
        $intent = $this->generate();
        $other = Company::factory()->create();
        $branch = Branch::factory()->for($other)->create();
        $user = User::factory()->create();
        Membership::factory()->for($other)->for($user)->owner()->create();
        $this->actingAs($user)->withSession(['active_company_id' => $other->id, 'active_branch_id' => $branch->id])
            ->postJson(route('orders.dynamic-qr.check', [$this->order->ulid, $intent->ulid]))->assertNotFound();
        $this->expectException(AuthorizationException::class);
        app(DynamicQrAction::class)->generate($this->order, $this->session, $user, 'foreign');
    }

    public function test_feature_flag_blocks_generation_but_preserves_pending_reconciliation(): void
    {
        $intent = $this->generate();
        config(['baneco.enabled' => false]);
        $this->login()->get(route('orders.checkout', $this->order->ulid))->assertOk()->assertSee('data-dynamic-qr', false);
        $this->paid();
        $this->assertSame(DynamicQrStatus::Paid, $this->check($intent)->status);
        $this->expectException(DomainException::class);
        $this->generate(key: 'flag-disabled');
    }

    public function test_numeric_bank_json_preserves_decimal_amount_and_large_transaction_id(): void
    {
        $this->order->update(['total' => '100.01']);
        $intent = $this->generate();
        $this->rawStatus = '{"responseCode":0,"statusQrCode":1,"payment":{"qrId":"bank-1","currency":"BOB","amount":100.01,"transactionId":9999999999999999999999}}';
        $this->assertSame(DynamicQrStatus::Paid, $this->check($intent)->status);
        $this->assertSame('100.01', $intent->refresh()->reconciliation_data['amount']);
        $this->assertSame('9999999999999999999999', $intent->reconciliation_data['transactionId']);
        $this->assertSame('100.01', Payment::query()->sole()->amount);
    }

    public function test_revoked_cashier_permission_requires_reconciliation_instead_of_impersonation(): void
    {
        $intent = $this->generate();
        Membership::query()->where('user_id', $this->user->id)->update(['is_active' => false]);
        $this->paid();
        $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
        $this->assertSame('payment_context_rejected', $intent->refresh()->issue_code);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_database_rejects_duplicate_bank_ids_and_cross_company_intent_dependencies(): void
    {
        $intent = $this->generate();
        $copy = $intent->replicate(['ulid', 'active_order_id', 'commercial_reference', 'idempotency_key']);
        $copy->commercial_reference = 'new-reference';
        $copy->idempotency_key = 'new-key';
        try {
            $copy->save();
            $this->fail('Duplicate bank qrId must be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('dynamic_qr_intents', 1);
        }
        $other = Company::factory()->create();
        $copy->bank_qr_id = 'another-qr';
        $copy->company_id = $other->id;
        $this->expectException(QueryException::class);
        $copy->save();
    }

    public function test_poll_frequency_is_enforced_on_server_and_cancellation_timeout_keeps_active_qr(): void
    {
        $intent = $this->generate();
        app(DynamicQrAction::class)->check($intent);
        app(DynamicQrAction::class)->check($intent);
        $requests = Http::recorded(fn ($r) => str_contains($r->url(), 'statusQR'));
        $this->assertCount(1, $requests);
        $this->failure = 'cancel_timeout';
        $this->assertSame(DynamicQrStatus::Pending, $this->check($intent, true)->status);
        $this->assertNotNull($intent->refresh()->active_order_id);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_branch_override_is_isolated_and_settings_versions_remain_attached_to_intents(): void
    {
        $override = BankQrSetting::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'active_scope' => $this->company->id.':'.$this->branch->id, 'credentials' => $this->setting->credentials]);
        $intent = $this->generate();
        $this->assertSame($override->id, $intent->bank_qr_setting_id);
        $override->update(['active_scope' => null]);
        $this->assertSame($override->id, $intent->refresh()->setting->id);
        $this->assertSame($this->setting->id, BankQrSetting::forOrder($this->order)->id);
        $otherBranch = Branch::factory()->for($this->company)->create();
        $this->login()->withSession(['active_branch_id' => $otherBranch->id])->getJson(route('orders.dynamic-qr.show', $this->order->ulid))->assertNotFound();
    }

    public function test_multiple_or_malformed_payment_details_cannot_confirm(): void
    {
        $intent = $this->generate();
        foreach (['unexpected-string', [['qrId' => 'bank-1'], ['qrId' => 'bank-1']], []] as $details) {
            $this->bankStatus = ['responseCode' => 0, 'statusQrCode' => 1, 'payment' => $details];
            $this->assertSame(DynamicQrStatus::ReconciliationRequired, $this->check($intent)->status);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_alerts_show_quarantined_operations_and_new_attempts_cannot_hide_them(): void
    {
        $intent = $this->generate();
        $this->check($intent, true);
        $this->paid();
        $this->check($intent);
        $this->login()->get(route('cash.index'))->assertOk()->assertSee('conciliación requerida')->assertSee($intent->ulid);
        $this->expectException(DomainException::class);
        $this->generate(key: 'hide-late-payment');
    }
}
