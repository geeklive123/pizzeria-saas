<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mismatch = DB::table('bank_qr_deposit_evidence as evidence')
            ->leftJoin('dynamic_qr_intents as intent', 'intent.id', '=', 'evidence.intent_id')
            ->where(fn ($query) => $query->whereNull('intent.id')
                ->orWhereColumn('evidence.company_id', '!=', 'intent.company_id')
                ->orWhereColumn('evidence.branch_id', '!=', 'intent.branch_id'))
            ->exists();
        if ($mismatch) {
            throw new RuntimeException('Existen evidencias bancarias fuera del ámbito de su intento; revisar antes de migrar.');
        }

        Schema::table('dynamic_qr_intents', function (Blueprint $table): void {
            $table->unique(['company_id', 'branch_id', 'id'], 'dqr_company_branch_id_uq');
        });
        Schema::table('bank_qr_deposit_evidence', function (Blueprint $table): void {
            $table->foreign(['company_id', 'branch_id', 'intent_id'], 'bqdepo_intent_scope_fk')
                ->references(['company_id', 'branch_id', 'id'])->on('dynamic_qr_intents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('La restricción de ámbito de evidencias requiere un procedimiento de reversión revisado.');
    }
};
