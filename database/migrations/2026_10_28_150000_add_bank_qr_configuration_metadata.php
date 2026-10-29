<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_qr_settings', function (Blueprint $table): void {
            $table->string('base_url', 160)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('due_days')->default(1);
            $table->boolean('technical_logging')->default(true);
        });
        Schema::create('bank_qr_setting_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bank_qr_setting_id');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->timestamp('created_at');
            $table->foreign(['bank_qr_setting_id', 'company_id'], 'bqsa_setting_company_fk')->references(['id', 'company_id'])->on('bank_qr_settings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_qr_setting_audits');
        Schema::table('bank_qr_settings', function (Blueprint $table): void {
            $table->dropColumn(['base_url', 'enabled', 'due_days', 'technical_logging']);
        });
    }
};
