<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_transfers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('destination_cash_session_id');
            $table->foreignId('destination_cashier_id');
            $table->foreignId('authorized_by');
            $table->string('reason', 500);
            $table->json('source_cash_session_ids');
            $table->json('source_cashier_ids');
            $table->json('payment_ids');
            $table->json('source_cash_movement_ids');
            $table->json('compensating_cash_movement_ids');
            $table->timestamp('created_at');

            $table->unique('ulid', 'cash_session_transfer_ulid_uq');
            $table->index(['company_id', 'branch_id', 'order_id', 'created_at'], 'cash_session_transfer_order_ix');
            $table->foreign('company_id', 'cash_session_transfer_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('destination_cashier_id', 'cash_session_transfer_cashier_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('authorized_by', 'cash_session_transfer_authorized_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'cash_session_transfer_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'cash_session_transfer_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['destination_cash_session_id', 'company_id', 'branch_id'], 'cash_session_transfer_destination_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_transfers');
    }
};
