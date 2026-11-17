<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_qr_operations', function (Blueprint $table): void {
            $table->foreignId('dynamic_qr_intent_id')->nullable()->unique()->constrained('dynamic_qr_intents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Los vínculos bancarios deben conservarse para auditoría.');
    }
};
