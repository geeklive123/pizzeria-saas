<?php

namespace App\Services;

use App\Enums\PrintAttemptStatus;
use App\Models\PrintAgent;
use App\Models\PrintAttempt;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrintAgentQueueService
{
    public function claim(PrintAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent): ?array {
            $now = now();
            $claimTimeout = max(30, (int) config('thermal-printing.agent.claim_timeout_seconds', 120));
            $job = PrintAttempt::query()
                ->where('company_id', $agent->company_id)
                ->where('branch_id', $agent->branch_id)
                ->whereNotNull('document_payload')
                ->where(function ($query) use ($now): void {
                    $query->where(function ($available) use ($now): void {
                        $available->whereIn('status', [PrintAttemptStatus::Pending->value, PrintAttemptStatus::Failed->value])
                            ->whereNotNull('available_at')->where('available_at', '<=', $now);
                    })->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', PrintAttemptStatus::Claimed->value)
                            ->whereNotNull('claim_expires_at')->where('claim_expires_at', '<=', $now);
                    });
                })
                ->orderBy('attempted_at')->orderBy('id')->lockForUpdate()->first();

            if (! $job) {
                return null;
            }

            $claimToken = Str::random(64);
            $job->update([
                'status' => PrintAttemptStatus::Claimed,
                'claimed_by_agent_id' => $agent->getKey(),
                'claimed_at' => $now,
                'claim_expires_at' => $now->copy()->addSeconds($claimTimeout),
                'claim_token_hash' => hash('sha256', $claimToken),
                'attempts' => $job->attempts + 1,
                'error_message' => null,
            ]);

            return ['job' => $job->refresh(), 'claim_token' => $claimToken];
        });
    }

    public function printed(PrintAgent $agent, string $ulid, string $claimToken): PrintAttempt
    {
        return $this->finish($agent, $ulid, $claimToken, true, null);
    }

    public function failed(PrintAgent $agent, string $ulid, string $claimToken, string $error): PrintAttempt
    {
        return $this->finish($agent, $ulid, $claimToken, false, Str::limit(trim($error), 500, ''));
    }

    private function finish(PrintAgent $agent, string $ulid, string $claimToken, bool $printed, ?string $error): PrintAttempt
    {
        return DB::transaction(function () use ($agent, $ulid, $claimToken, $printed, $error): PrintAttempt {
            $job = PrintAttempt::query()->where('company_id', $agent->company_id)
                ->where('branch_id', $agent->branch_id)->where('ulid', $ulid)->lockForUpdate()->firstOrFail();

            if ($job->status !== PrintAttemptStatus::Claimed
                || $job->claimed_by_agent_id !== $agent->getKey()
                || ! hash_equals((string) $job->claim_token_hash, hash('sha256', $claimToken))) {
                throw new DomainException('El trabajo ya no pertenece a este claim.');
            }

            $job->update([
                'status' => $printed ? PrintAttemptStatus::Printed : PrintAttemptStatus::Failed,
                'printed_at' => $printed ? now() : null,
                'available_at' => $printed ? null : now()->addSeconds(max(1, (int) config('thermal-printing.agent.retry_delay_seconds', 15))),
                'error_message' => $printed ? null : ($error ?: 'El agente informó un error de impresión.'),
                'claimed_at' => null,
                'claim_expires_at' => null,
                'claim_token_hash' => null,
            ]);

            if ($printed) {
                $agent->update(['last_printed_at' => now()]);
            }

            return $job->refresh();
        });
    }
}
