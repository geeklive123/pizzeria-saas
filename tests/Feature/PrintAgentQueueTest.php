<?php

namespace Tests\Feature;

use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Http\Middleware\AuthenticatePrintAgent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PrintAgent;
use App\Models\PrintAttempt;
use App\Printing\FileThermalPrinterTransport;
use App\Services\PrintAgentQueueService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintAgentQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('thermal-printing.agent.require_https', false);
        config()->set('thermal-printing.agent.retry_delay_seconds', 1);
    }

    public function test_token_is_required_and_agent_only_claims_its_company_and_branch(): void
    {
        [$company, $branch] = $this->context();
        [$otherCompany, $otherBranch] = $this->context();
        [$agent, $token] = $this->agent($company, $branch);
        $own = $this->job($company, $branch);
        $other = $this->job($otherCompany, $otherBranch);

        $this->postJson('/api/print-agent/jobs/claim')->assertUnauthorized();
        $this->withToken(Str::random(80))->postJson('/api/print-agent/jobs/claim')->assertUnauthorized();

        $response = $this->withToken($token)->postJson('/api/print-agent/jobs/claim')
            ->assertOk()->assertJsonPath('job.id', $own->ulid);

        $this->assertSame($agent->id, $own->refresh()->claimed_by_agent_id);
        $this->assertSame(PrintAttemptStatus::Claimed, $own->status);
        $this->assertSame(PrintAttemptStatus::Pending, $other->refresh()->status);
        $this->assertNotSame((string) $own->claim_token_hash, $response->json('job.claim_token'));
        $this->assertSame((string) $own->claim_token_hash, hash('sha256', $response->json('job.claim_token')));
    }

    public function test_agent_endpoint_requires_https_when_enabled(): void
    {
        [$company, $branch] = $this->context();
        [, $token] = $this->agent($company, $branch);
        $this->job($company, $branch);
        config()->set('thermal-printing.agent.require_https', true);

        $this->withToken($token)->postJson('/api/print-agent/jobs/claim')
            ->assertStatus(400)->assertJsonPath('message', 'El agente de impresión requiere HTTPS.');
        $secureRequest = Request::create('https://prints.example/api/print-agent/jobs/claim', 'POST');
        $secureRequest->headers->set('Authorization', 'Bearer '.$token);
        $response = app(AuthenticatePrintAgent::class)->handle(
            $secureRequest,
            fn () => response()->json(['authenticated' => true]),
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_claim_is_exclusive_completion_is_scoped_and_printed_job_never_returns(): void
    {
        [$company, $branch] = $this->context();
        [$agentA, $tokenA] = $this->agent($company, $branch, 'Caja A');
        [, $tokenB] = $this->agent($company, $branch, 'Caja B');
        $job = $this->job($company, $branch);

        $claim = $this->withToken($tokenA)->postJson('/api/print-agent/jobs/claim')->assertOk();
        $this->withToken($tokenB)->postJson('/api/print-agent/jobs/claim')->assertNoContent();
        $claimToken = $claim->json('job.claim_token');

        $this->withToken($tokenB)->postJson('/api/print-agent/jobs/'.$job->ulid.'/printed', [
            'claim_token' => $claimToken,
        ])->assertStatus(409);
        $this->withToken($tokenA)->postJson('/api/print-agent/jobs/'.$job->ulid.'/printed', [
            'claim_token' => $claimToken,
        ])->assertOk()->assertJsonPath('job.status', 'printed');

        $this->assertSame(PrintAttemptStatus::Printed, $job->refresh()->status);
        $this->assertNotNull($job->printed_at);
        $this->assertNotNull($agentA->refresh()->last_printed_at);
        $this->withToken($tokenA)->postJson('/api/print-agent/jobs/claim')->assertNoContent();
    }

    public function test_failed_job_retries_and_stale_claim_is_recovered_atomically(): void
    {
        [$company, $branch] = $this->context();
        [$agentA, $tokenA] = $this->agent($company, $branch, 'Agente A');
        [$agentB, $tokenB] = $this->agent($company, $branch, 'Agente B');
        $failedJob = $this->job($company, $branch);
        $claim = $this->withToken($tokenA)->postJson('/api/print-agent/jobs/claim')->assertOk();

        $this->withToken($tokenA)->postJson('/api/print-agent/jobs/'.$failedJob->ulid.'/failed', [
            'claim_token' => $claim->json('job.claim_token'),
            'error' => 'Impresora desconectada',
        ])->assertOk()->assertJsonPath('job.status', 'failed');
        $this->assertSame(1, $failedJob->refresh()->attempts);
        $this->travel(2)->seconds();
        $retry = $this->withToken($tokenB)->postJson('/api/print-agent/jobs/claim')->assertOk();
        $this->assertSame($failedJob->ulid, $retry->json('job.id'));
        $this->assertSame(2, $failedJob->refresh()->attempts);

        $failedJob->update(['claim_expires_at' => now()->subSecond()]);
        $stale = $this->withToken($tokenA)->postJson('/api/print-agent/jobs/claim')->assertOk();
        $this->assertSame($failedJob->ulid, $stale->json('job.id'));
        $this->assertSame($agentA->id, $failedJob->refresh()->claimed_by_agent_id);
        $this->assertSame(3, $failedJob->attempts);

        $this->withToken($tokenB)->postJson('/api/print-agent/jobs/'.$failedJob->ulid.'/printed', [
            'claim_token' => $retry->json('job.claim_token'),
        ])->assertStatus(409);
        $this->withToken($tokenA)->postJson('/api/print-agent/jobs/'.$failedJob->ulid.'/printed', [
            'claim_token' => $stale->json('job.claim_token'),
        ])->assertOk();
    }

    public function test_service_rejects_confirmation_from_another_branch(): void
    {
        [$company, $branch] = $this->context();
        $otherBranch = Branch::factory()->for($company)->create();
        [$agent] = $this->agent($company, $branch);
        [$otherAgent] = $this->agent($company, $otherBranch);
        $job = $this->job($company, $branch);
        $claim = app(PrintAgentQueueService::class)->claim($agent);

        $this->expectException(ModelNotFoundException::class);
        app(PrintAgentQueueService::class)->printed($otherAgent, $job->ulid, $claim['claim_token']);
    }

    public function test_file_transport_writes_each_copy_without_a_physical_printer(): void
    {
        $directory = storage_path('framework/testing/print-agent-'.Str::lower(Str::random(8)));
        config()->set('thermal-printing.agent.output_directory', $directory);

        app(FileThermalPrinterTransport::class)->send('EPSON Cocina', "ESC-POS\x1dV\x00", 2);

        $files = File::files($directory);
        $this->assertCount(2, $files);
        $this->assertSame("ESC-POS\x1dV\x00", File::get($files[0]->getPathname()));
        File::deleteDirectory($directory);
    }

    /** @return array{Company,Branch} */
    private function context(): array
    {
        $company = Company::factory()->create();

        return [$company, Branch::factory()->for($company)->create()];
    }

    /** @return array{PrintAgent,string} */
    private function agent(Company $company, Branch $branch, string $name = 'Windows Epson'): array
    {
        $token = Str::random(80);
        $agent = PrintAgent::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
        ]);

        return [$agent, $token];
    }

    private function job(Company $company, Branch $branch): PrintAttempt
    {
        return PrintAttempt::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'purpose' => PrinterPurpose::Kitchen,
            'windows_printer_name' => 'Cocina',
            'copies' => 1,
            'status' => PrintAttemptStatus::Pending,
            'attempted_at' => now(),
            'available_at' => now(),
            'idempotency_key' => 'test:'.Str::ulid(),
            'document_payload' => base64_encode('ticket'),
        ]);
    }
}
