<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_qr_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bank_qr_setting_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_company_id')->nullable()->unique();
            $table->decimal('amount', 16, 2);
            $table->string('commercial_reference', 32)->unique();
            $table->string('bank_qr_id', 190)->nullable();
            $table->mediumText('qr_image')->nullable();
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->string('operation', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('response_code', 6)->nullable();
            $table->string('issue_code', 80)->nullable();
            $table->timestamps();
            $table->foreign(['bank_qr_setting_id', 'company_id'], 'bqd_setting_company_fk')->references(['id', 'company_id'])->on('bank_qr_settings')->restrictOnDelete();
            $table->foreign(['active_company_id'], 'bqd_active_company_fk')->references(['id'])->on('companies')->restrictOnDelete();
            $table->unique(['id', 'company_id'], 'bqd_id_company_uq');
            $table->index(['company_id', 'created_at']);
        });
        Schema::create('bank_qr_diagnostic_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('diagnostic_id');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('operation', 32);
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('response_code', 6)->nullable();
            $table->string('issue_code', 80)->nullable();
            $table->timestamp('created_at');
            $table->foreign(['diagnostic_id', 'company_id'], 'bqde_diagnostic_company_fk')->references(['id', 'company_id'])->on('bank_qr_diagnostics')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_qr_diagnostic_events');
        Schema::dropIfExists('bank_qr_diagnostics');
    }
};
