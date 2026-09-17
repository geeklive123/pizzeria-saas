<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancellation_audits', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_cancellation_audits', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->change();
        });
    }
};
