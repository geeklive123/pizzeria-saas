<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->boolean('requires_preparation')->default(true)->after('price');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('requires_preparation')->default(true)->after('fulfillment_type');
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->timestamp('preparing_at')->nullable()->after('sent_at');
            $table->timestamp('ready_at')->nullable()->after('preparing_at');
            $table->timestamp('served_at')->nullable()->after('ready_at');
            $table->timestamp('cancelled_at')->nullable()->after('served_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
            $table->foreign('cancelled_by', 'ord_items_cancelled_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'branch_id', 'status'], 'ord_items_kitchen_status_ix');
        });

        $directVariantIds = DB::table('inventory_items')
            ->whereNotNull('product_variant_id')
            ->pluck('product_variant_id');
        DB::table('product_variants')->whereIn('id', $directVariantIds)
            ->update(['requires_preparation' => false]);

        DB::table('order_items')->where('status', 'new')->update(['status' => 'draft']);
        foreach (DB::table('product_variants')->select('id', 'requires_preparation')->get() as $variant) {
            DB::table('order_items')->where('product_variant_id', $variant->id)
                ->update(['requires_preparation' => $variant->requires_preparation]);
        }

        Schema::create('kitchen_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedInteger('sequence_number');
            $table->timestamp('dispatched_at');
            $table->foreignId('dispatched_by')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'kdispatch_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'kdispatch_context_uq');
            $table->unique(['order_id', 'sequence_number'], 'kdispatch_order_seq_uq');
            $table->index(['company_id', 'branch_id', 'dispatched_at'], 'kdispatch_listing_ix');
            $table->foreign('company_id', 'kdispatch_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('dispatched_by', 'kdispatch_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'kdispatch_branch_ctx_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'kdispatch_order_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('kitchen_dispatch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('kitchen_dispatch_id');
            $table->unsignedBigInteger('order_item_id');
            $table->timestamps();

            $table->unique('order_item_id', 'kdispatch_items_order_item_uq');
            $table->index(['company_id', 'branch_id', 'kitchen_dispatch_id'], 'kdispatch_items_listing_ix');
            $table->foreign('company_id', 'kdispatch_items_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['kitchen_dispatch_id', 'company_id', 'branch_id'], 'kdispatch_items_dispatch_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('kitchen_dispatches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_item_id', 'company_id', 'branch_id'], 'kdispatch_items_order_item_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('order_items')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_dispatch_items');
        Schema::dropIfExists('kitchen_dispatches');

        DB::table('order_items')->whereIn('status', ['draft', 'sent', 'preparing', 'ready', 'served'])
            ->update(['status' => 'new']);

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign('ord_items_cancelled_by_fk');
            $table->dropIndex('ord_items_kitchen_status_ix');
            $table->dropColumn([
                'requires_preparation', 'sent_at', 'preparing_at', 'ready_at', 'served_at',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('requires_preparation');
        });
    }
};
