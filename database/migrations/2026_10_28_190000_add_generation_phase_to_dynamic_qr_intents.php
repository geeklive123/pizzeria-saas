<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_qr_intents', function (Blueprint $table): void {
            // NULL deliberately means unknown, including every historical attempt.
            $table->string('generation_phase', 32)->nullable();
        });
    }

    public function down(): void
    {
        // Dropping this evidence may make an already-sent request appear safe to repeat.
        throw new RuntimeException('No se puede eliminar automáticamente la evidencia de generación QR.');
    }
};
