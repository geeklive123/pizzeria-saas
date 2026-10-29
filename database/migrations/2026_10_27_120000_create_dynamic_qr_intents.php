<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_qr_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            // Nullable on retired versions; intents retain their original credentials.
            $table->string('active_scope', 80)->nullable()->unique();
            $table->text('credentials');
            $table->timestamps();
            $table->unique(['id', 'company_id'], 'bank_setting_context_uq');
            $table->foreign(['branch_id', 'company_id'], 'bank_setting_branch_fk')->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
        });
        Schema::create('dynamic_qr_intents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('kitchen_dispatch_id')->nullable();
            $table->unsignedBigInteger('cash_session_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bank_qr_setting_id');
            $table->unsignedBigInteger('payment_id')->nullable()->unique();
            // Conservative policy: one remotely payable QR per order, including all batches.
            $table->unsignedBigInteger('active_order_id')->nullable()->unique();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3)->default('BOB');
            $table->string('commercial_reference', 32)->unique();
            $table->string('bank_qr_id', 190)->nullable()->unique();
            $table->string('idempotency_key', 64);
            $table->string('status', 32)->index();
            $table->string('bank_status', 20)->nullable();
            $table->mediumText('qr_image')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ticket_enqueued_at')->nullable();
            $table->string('issue_code', 80)->nullable();
            $table->json('reconciliation_data')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'dynamic_qr_idempotency_uq');
            $table->index(['company_id', 'branch_id', 'status'], 'dynamic_qr_listing_ix');
            foreach (['order_id' => 'orders', 'active_order_id' => 'orders', 'cash_session_id' => 'cash_sessions', 'kitchen_dispatch_id' => 'kitchen_dispatches', 'payment_id' => 'payments'] as $column => $target) {
                $table->foreign([$column, 'company_id', 'branch_id'], 'dqr_'.$column.'_fk')->references(['id', 'company_id', 'branch_id'])->on($target)->restrictOnDelete();
            }
            $table->foreign(['bank_qr_setting_id', 'company_id'], 'dqr_setting_fk')->references(['id', 'company_id'])->on('bank_qr_settings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_qr_intents');
        Schema::dropIfExists('bank_qr_settings');
    }
};
