<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_qr_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('online_order_request_id')->unique();
            $table->unsignedBigInteger('cash_session_id');
            $table->foreignId('original_cashier_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('system_user_id');
            $table->string('status', 32);
            $table->timestamps();
            $table->foreign(['online_order_request_id', 'company_id', 'branch_id'], 'online_qr_operation_request_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('online_order_requests')->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id', 'branch_id'], 'online_qr_operation_cash_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign(['system_user_id', 'company_id', 'branch_id'], 'online_qr_operation_actor_fk')
                ->references(['user_id', 'company_id', 'branch_id'])->on('online_system_identities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Los contextos de operaciones online deben conservarse para auditoría.');
    }
};
