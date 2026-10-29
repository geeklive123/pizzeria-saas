<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_qr_diagnostics', function (Blueprint $table): void {
            $table->index('active_company_id', 'bqd_active_company_idx');
            $table->dropUnique('bank_qr_diagnostics_active_company_id_unique');
            $table->unsignedSmallInteger('generation_http_status')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('bank_qr_diagnostics')->whereNotNull('active_company_id')->groupBy('active_company_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('No se puede restaurar la restricción: hay múltiples diagnósticos activos por empresa.');
        }
        Schema::table('bank_qr_diagnostics', function (Blueprint $table): void {
            $table->unique('active_company_id');
            $table->dropIndex('bqd_active_company_idx');
            $table->dropColumn('generation_http_status');
        });
    }
};
