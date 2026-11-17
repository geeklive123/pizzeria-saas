<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_qr_dispositions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('online_order_request_id')->constrained('online_order_requests')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('online_qr_operation_id')->unique()->constrained('online_qr_operations')->restrictOnDelete();
            $table->foreignId('dynamic_qr_intent_id')->unique()->constrained('dynamic_qr_intents')->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->string('disposition', 32);
            $table->decimal('amount', 12, 2);
            $table->date('action_date');
            $table->text('reason');
            $table->text('evidence');
            $table->string('bank_qr_id')->nullable();
            $table->string('bank_status', 20)->nullable();
            $table->dateTime('bank_checked_at')->nullable();
            $table->text('bank_evidence');
            $table->text('inventory_result');
            $table->string('order_result', 32);
            $table->dateTime('resolved_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Las disposiciones financieras deben conservarse para auditoría.');
    }
};
