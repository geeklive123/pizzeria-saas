<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_qr_deposit_evidence', function (Blueprint $table): void {
            $table->id();
            $table->ulid('request_key')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('intent_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('deposit_date');
            $table->text('details');
            $table->timestamp('created_at');
            $table->foreign(['intent_id'], 'bqdepo_intent_fk')->references('id')->on('dynamic_qr_intents')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'bqdepo_branch_company_fk')->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
            $table->index(['company_id', 'branch_id', 'intent_id'], 'bqdepo_scope_idx');
        });
    }

    public function down(): void
    {
        // La evidencia financiera no se elimina mediante rollback.
    }
};
