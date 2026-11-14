<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_order_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            // Durable fence for an abandoned idempotency key; never shown as an order.
            $table->boolean('is_fence')->default(false);
            $table->string('customer_name', 120);
            $table->string('customer_phone', 16);
            $table->text('notes')->nullable();
            $table->string('fulfillment', 20)->default('pickup');
            $table->string('status', 32)->default('pending_confirmation');
            $table->decimal('subtotal', 16, 2);
            $table->decimal('total', 16, 2);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'idempotency_key'], 'online_request_key_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'online_request_context_uq');
            $table->index(['company_id', 'branch_id', 'status', 'created_at'], 'online_request_listing_ix');
            $table->foreign(['branch_id', 'company_id'], 'online_request_branch_fk')->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('online_order_request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('online_order_request_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('product_name');
            $table->string('variant_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 16, 2);
            $table->decimal('subtotal', 16, 2);
            $table->timestamps();
            $table->foreign(['online_order_request_id', 'company_id', 'branch_id'], 'online_item_request_fk')->references(['id', 'company_id', 'branch_id'])->on('online_order_requests')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'company_id'], 'online_item_variant_fk')->references(['id', 'company_id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_request_items');
        Schema::dropIfExists('online_order_requests');
    }
};
