<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->decimal('unit_price', 12, 2)->unsigned();
            $table->decimal('line_total', 16, 2)->unsigned();
            $table->string('fulfillment_type', 20);
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'order_items_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'order_items_context_uq');
            $table->index(['company_id', 'branch_id', 'order_id', 'status'], 'order_items_order_status_ix');
            $table->foreign('company_id', 'order_items_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'order_items_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'order_items_order_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['product_variant_id', 'company_id'], 'order_items_variant_company_fk')
                ->references(['id', 'company_id'])->on('product_variants')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->string('status', 20);
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'inventory_item_id'], 'inv_res_item_inventory_uq');
            $table->index(['company_id', 'branch_id', 'inventory_item_id', 'status'], 'inv_res_available_ix');
            $table->foreign('company_id', 'inv_res_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'inv_res_order_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_item_id', 'company_id', 'branch_id'], 'inv_res_order_item_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('order_items')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'], 'inv_res_inventory_item_company_fk')
                ->references(['id', 'company_id'])->on('inventory_items')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'inv_res_branch_company_fk')
                ->references(['id', 'company_id'])
                ->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('order_items');
    }
};
