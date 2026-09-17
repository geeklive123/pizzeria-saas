<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_dispatches', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('settled_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
            $table->foreign('cancelled_by', 'kdispatch_cancelled_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_dispatches', function (Blueprint $table): void {
            $table->dropForeign('kdispatch_cancelled_by_fk');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
