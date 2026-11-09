<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('financial_version')->default(0);
        });

        Schema::table('financial_payment_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_financial_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('financial_payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('expected_financial_version');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('financial_version');
        });
    }
};
