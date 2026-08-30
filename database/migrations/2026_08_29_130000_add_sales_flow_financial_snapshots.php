<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('table_charge_mode', 30)->default('at_end')->after('is_active');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('charge_mode', 30)->default('at_end')->after('type');
            $table->decimal('pizza_base_subtotal', 16, 2)->unsigned()->default(0)->after('subtotal');
            $table->decimal('extras_subtotal', 16, 2)->unsigned()->default(0)->after('pizza_base_subtotal');
            $table->decimal('other_subtotal', 16, 2)->unsigned()->default(0)->after('extras_subtotal');
            $table->decimal('discount_percentage', 5, 2)->unsigned()->nullable()->after('other_subtotal');
            $table->json('financial_snapshot')->nullable()->after('total');
        });
        Schema::table('kitchen_dispatches', function (Blueprint $table): void {
            $table->string('status', 30)->default('released')->after('sequence_number');
            $table->decimal('gross_subtotal', 16, 2)->unsigned()->default(0)->after('dispatched_by');
            $table->decimal('pizza_base_subtotal', 16, 2)->unsigned()->default(0)->after('gross_subtotal');
            $table->decimal('extras_subtotal', 16, 2)->unsigned()->default(0)->after('pizza_base_subtotal');
            $table->decimal('other_subtotal', 16, 2)->unsigned()->default(0)->after('extras_subtotal');
            $table->decimal('discount_percentage', 5, 2)->unsigned()->nullable()->after('other_subtotal');
            $table->decimal('discount_total', 16, 2)->unsigned()->default(0)->after('discount_percentage');
            $table->decimal('total', 16, 2)->unsigned()->default(0)->after('discount_total');
            $table->json('financial_snapshot')->nullable()->after('total');
            $table->timestamp('released_at')->nullable()->after('financial_snapshot');
            $table->timestamp('settled_at')->nullable()->after('released_at');
            $table->index(['company_id', 'branch_id', 'status', 'settled_at'], 'kdispatch_financial_status_ix');
        });
        DB::table('kitchen_dispatches')->update(['status' => 'released', 'released_at' => DB::raw('dispatched_at')]);
        Schema::table('kitchen_dispatch_items', function (Blueprint $table): void {
            $table->string('financial_type', 30)->default('other')->after('order_item_id');
            $table->decimal('gross_total', 16, 2)->unsigned()->default(0)->after('financial_type');
            $table->decimal('pizza_base_total', 16, 2)->unsigned()->default(0)->after('gross_total');
            $table->decimal('extras_total', 16, 2)->unsigned()->default(0)->after('pizza_base_total');
            $table->decimal('other_total', 16, 2)->unsigned()->default(0)->after('extras_total');
            $table->decimal('discount_total', 16, 2)->unsigned()->default(0)->after('other_total');
            $table->decimal('net_total', 16, 2)->unsigned()->default(0)->after('discount_total');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('kitchen_dispatch_id')->nullable()->after('order_id');
            $table->index(['company_id', 'branch_id', 'kitchen_dispatch_id', 'status'], 'payment_dispatch_listing_ix');
            $table->foreign(['kitchen_dispatch_id', 'company_id', 'branch_id'], 'payment_dispatch_context_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('kitchen_dispatches')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign('payment_dispatch_context_fk');
            $table->dropIndex('payment_dispatch_listing_ix');
            $table->dropColumn('kitchen_dispatch_id');
        });
        Schema::table('kitchen_dispatch_items', function (Blueprint $table): void {
            $table->dropColumn(['financial_type', 'gross_total', 'pizza_base_total', 'extras_total', 'other_total', 'discount_total', 'net_total']);
        });
        Schema::table('kitchen_dispatches', function (Blueprint $table): void {
            $table->dropIndex('kdispatch_financial_status_ix');
            $table->dropColumn(['status', 'gross_subtotal', 'pizza_base_subtotal', 'extras_subtotal', 'other_subtotal', 'discount_percentage', 'discount_total', 'total', 'financial_snapshot', 'released_at', 'settled_at']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['charge_mode', 'pizza_base_subtotal', 'extras_subtotal', 'other_subtotal', 'discount_percentage', 'financial_snapshot']);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('table_charge_mode');
        });
    }
};
