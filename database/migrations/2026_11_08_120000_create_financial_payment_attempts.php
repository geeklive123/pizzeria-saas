<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique('financial_attempt_ulid_uq');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('active_order_id')->nullable();
            $table->unsignedBigInteger('kitchen_dispatch_id')->nullable();
            $table->unsignedBigInteger('cash_session_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('method', 20);
            $table->decimal('amount', 16, 2)->unsigned();
            $table->decimal('received_amount', 16, 2)->unsigned()->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'financial_attempt_key_uq');
            $table->unique('active_order_id', 'financial_attempt_active_order_uq');
            $table->index(['company_id', 'branch_id', 'order_id', 'status'], 'financial_attempt_scope_ix');
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'financial_attempt_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id', 'branch_id'], 'financial_attempt_session_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign(['active_order_id', 'company_id', 'branch_id'], 'financial_attempt_active_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->restrictOnDelete();
            $table->foreign(['kitchen_dispatch_id', 'company_id', 'branch_id'], 'financial_attempt_dispatch_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('kitchen_dispatches')->restrictOnDelete();
            $table->foreign(['payment_id', 'company_id', 'branch_id'], 'financial_attempt_payment_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('payments')->restrictOnDelete();
            $table->foreign('user_id', 'financial_attempt_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_payment_attempts');
    }
};
