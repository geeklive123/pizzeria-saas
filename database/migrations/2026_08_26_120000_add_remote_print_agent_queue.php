<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_agents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 120);
            $table->char('token_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'print_agents_ulid_uq');
            $table->unique('token_hash', 'print_agents_token_hash_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'print_agents_context_uq');
            $table->index(['company_id', 'branch_id', 'is_active'], 'print_agents_branch_ix');
            $table->foreign('company_id', 'print_agents_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'print_agents_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('print_attempts', function (Blueprint $table): void {
            $table->string('idempotency_key', 160)->nullable();
            $table->longText('document_payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('claimed_by_agent_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->char('claim_token_hash', 64)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('printed_at')->nullable();

            $table->unique('idempotency_key', 'print_attempts_idempotency_uq');
            $table->index(['company_id', 'branch_id', 'status', 'available_at'], 'print_attempts_queue_ix');
            $table->index(['claimed_by_agent_id', 'status'], 'print_attempts_agent_ix');
            $table->foreign(['claimed_by_agent_id', 'company_id', 'branch_id'], 'print_attempts_agent_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('print_agents')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('print_attempts', function (Blueprint $table): void {
            $table->dropForeign('print_attempts_agent_fk');
            $table->dropUnique('print_attempts_idempotency_uq');
            $table->dropIndex('print_attempts_queue_ix');
            $table->dropIndex('print_attempts_agent_ix');
            $table->dropColumn([
                'idempotency_key', 'document_payload', 'attempts', 'claimed_by_agent_id',
                'claimed_at', 'claim_expires_at', 'claim_token_hash', 'available_at', 'printed_at',
            ]);
        });

        Schema::dropIfExists('print_agents');
    }
};
