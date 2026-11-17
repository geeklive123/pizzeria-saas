<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_qr_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dynamic_qr_intent_id')->unique()->constrained('dynamic_qr_intents')->restrictOnDelete();
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->string('resolution', 32);
            $table->text('reason');
            $table->text('evidence');
            $table->timestamp('resolved_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Las resoluciones financieras deben conservarse para auditoría.');
    }
};
