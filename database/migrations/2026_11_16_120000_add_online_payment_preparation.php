<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('preparation_cash_session_id')->nullable();
            $table->timestamp('preparation_expires_at')->nullable();
            $table->foreign(['preparation_cash_session_id', 'company_id', 'branch_id'], 'online_prep_cash_context_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Las preparaciones deben conservarse para su auditoría.');
    }
};
