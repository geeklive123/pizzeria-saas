<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('cancellation_reason', 500)->nullable()->after('closed_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancellation_reason', 'cancelled_at']);
        });
    }
};
