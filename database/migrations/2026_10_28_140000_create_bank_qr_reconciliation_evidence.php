<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_qr_reconciliation_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('diagnostic_id');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->text('details');
            $table->text('candidate_qr_id')->nullable();
            $table->string('bank_status', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('response_code', 6)->nullable();
            $table->string('issue_code', 80)->nullable();
            $table->timestamp('created_at');
            $table->foreign(['diagnostic_id', 'company_id'], 'bqre_diagnostic_company_fk')->references(['id', 'company_id'])->on('bank_qr_diagnostics')->restrictOnDelete();
            $table->index(['diagnostic_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_qr_reconciliation_evidence');
    }
};
