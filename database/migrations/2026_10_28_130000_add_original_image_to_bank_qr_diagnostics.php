<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_qr_diagnostics', function (Blueprint $table): void {
            $table->longText('original_qr_image')->nullable();
            $table->string('generation_response_code', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_qr_diagnostics', function (Blueprint $table): void {
            $table->dropColumn(['original_qr_image', 'generation_response_code']);
        });
    }
};
