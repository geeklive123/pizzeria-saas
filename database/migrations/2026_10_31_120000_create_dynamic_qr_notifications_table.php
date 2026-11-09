<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_qr_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('intent_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'intent_id'], 'dqr_notice_user_intent_uq');
            $table->index(['company_id', 'branch_id', 'user_id', 'delivered_at'], 'dqr_notice_pending_ix');
            $table->foreign(['company_id', 'branch_id', 'intent_id'], 'dqr_notice_intent_fk')
                ->references(['company_id', 'branch_id', 'id'])->on('dynamic_qr_intents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_qr_notifications');
    }
};
