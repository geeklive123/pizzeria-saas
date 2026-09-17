<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cancellation_audits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('kitchen_dispatch_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('scope', 40);
            $table->json('snapshot');
            $table->timestamp('cancelled_at');
            $table->foreignId('cancelled_by');
            $table->string('cancellation_reason', 500);
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable();
            $table->string('restoration_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('ulid', 'order_cancel_audit_ulid_uq');
            $table->index(['company_id', 'branch_id', 'order_id', 'scope'], 'order_cancel_audit_lookup_ix');
            $table->index(['parent_id', 'order_item_id'], 'order_cancel_audit_parent_item_ix');
            $table->foreign('company_id', 'order_cancel_audit_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'order_cancel_audit_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'order_cancel_audit_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('kitchen_dispatch_id', 'order_cancel_audit_dispatch_fk')->references('id')->on('kitchen_dispatches')->restrictOnDelete();
            $table->foreign('order_item_id', 'order_cancel_audit_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('parent_id', 'order_cancel_audit_parent_fk')->references('id')->on('order_cancellation_audits')->restrictOnDelete();
            $table->foreign('cancelled_by', 'order_cancel_audit_cancelled_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('restored_by', 'order_cancel_audit_restored_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellation_audits');
    }
};
