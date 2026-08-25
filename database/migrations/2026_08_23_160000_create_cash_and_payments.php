<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('ulid', 'cash_reg_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'cash_reg_context_uq');
            $table->unique(['company_id', 'branch_id', 'name'], 'cash_reg_name_uq');
            $table->index(['company_id', 'branch_id', 'is_active'], 'cash_reg_listing_ix');
            $table->foreign('company_id', 'cash_reg_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'cash_reg_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('cash_register_id');
            $table->unsignedBigInteger('active_cash_register_id')->nullable();
            $table->foreignId('opened_by');
            $table->foreignId('closed_by')->nullable();
            $table->decimal('opening_amount', 16, 2)->unsigned();
            $table->decimal('expected_cash_amount', 16, 2)->unsigned()->default(0);
            $table->decimal('counted_cash_amount', 16, 2)->unsigned()->nullable();
            $table->decimal('difference_amount', 16, 2)->nullable();
            $table->string('status', 20);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'cash_session_ulid_uq');
            $table->unique('active_cash_register_id', 'cash_session_one_open_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'cash_session_context_uq');
            $table->index(['company_id', 'branch_id', 'status', 'opened_at'], 'cash_session_listing_ix');
            $table->foreign('company_id', 'cash_session_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('opened_by', 'cash_session_opened_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by', 'cash_session_closed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'cash_session_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['cash_register_id', 'company_id', 'branch_id'], 'cash_session_register_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_registers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['active_cash_register_id', 'company_id', 'branch_id'], 'cash_session_active_reg_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_registers')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('cash_session_id');
            $table->string('method', 20);
            $table->decimal('amount', 16, 2)->unsigned();
            $table->string('reference', 190)->nullable();
            $table->decimal('received_amount', 16, 2)->unsigned()->nullable();
            $table->decimal('change_amount', 16, 2)->unsigned()->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('received_by');
            $table->string('status', 20);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('idempotency_key', 64);
            $table->timestamps();

            $table->unique('ulid', 'payment_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'payment_context_uq');
            $table->unique(['company_id', 'idempotency_key'], 'payment_idempotency_uq');
            $table->unique('reversal_of_id', 'payment_reversal_uq');
            $table->index(['company_id', 'branch_id', 'order_id', 'status'], 'payment_order_listing_ix');
            $table->index(['cash_session_id', 'method', 'status'], 'payment_session_summary_ix');
            $table->foreign('company_id', 'payment_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('received_by', 'payment_received_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'payment_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'payment_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id', 'branch_id'], 'payment_session_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['reversal_of_id', 'company_id', 'branch_id'], 'payment_reversal_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('payments')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('cash_session_id');
            $table->string('type', 20);
            $table->decimal('amount', 16, 2)->unsigned();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by');
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'cash_move_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'cash_move_context_uq');
            $table->unique('reversal_of_id', 'cash_move_reversal_uq');
            $table->index(['cash_session_id', 'type', 'occurred_at'], 'cash_move_session_ix');
            $table->index(['reference_type', 'reference_id'], 'cash_move_reference_ix');
            $table->foreign('company_id', 'cash_move_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'cash_move_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'cash_move_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id', 'branch_id'], 'cash_move_session_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['reversal_of_id', 'company_id', 'branch_id'], 'cash_move_reversal_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_movements')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
