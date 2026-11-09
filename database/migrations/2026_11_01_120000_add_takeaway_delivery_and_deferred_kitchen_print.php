<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('takeaway_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'delivered_at'], 'takeaway_delivery_pending_ix');
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'takeaway_delivery_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->restrictOnDelete();
        });
        Schema::table('kitchen_dispatches', function (Blueprint $table): void {
            $table->timestamp('auto_kitchen_print_requested_at')->nullable();
            $table->timestamp('auto_kitchen_print_enqueued_at')->nullable();
            $table->foreignId('auto_kitchen_print_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['auto_kitchen_print_requested_at', 'auto_kitchen_print_enqueued_at'], 'kdispatch_print_pending_ix');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_dispatches', function (Blueprint $table): void {
            $table->dropForeign(['auto_kitchen_print_user_id']);
            $table->dropIndex('kdispatch_print_pending_ix');
            $table->dropColumn(['auto_kitchen_print_requested_at', 'auto_kitchen_print_enqueued_at', 'auto_kitchen_print_user_id']);
        });
        Schema::dropIfExists('takeaway_deliveries');
    }
};
