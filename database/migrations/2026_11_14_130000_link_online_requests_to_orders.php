<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_order_id')->nullable()->unique('online_request_pos_order_uq');
            $table->foreignId('converted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreign(['pos_order_id', 'company_id', 'branch_id'], 'online_request_pos_context_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->dropForeign('online_request_pos_context_fk');
            $table->dropConstrainedForeignId('converted_by');
            $table->dropUnique('online_request_pos_order_uq');
            $table->dropColumn(['pos_order_id', 'converted_at']);
        });
    }
};
