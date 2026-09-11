<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sequences', function (Blueprint $table): void {
            $table->unsignedBigInteger('next_operational_number')->default(1)->after('next_number');
        });

        DB::table('order_sequences')->update([
            'next_operational_number' => DB::raw('next_number'),
        ]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('operational_number')->nullable()->after('order_number');
            $table->unique(['company_id', 'branch_id', 'operational_number'], 'orders_operational_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_operational_number_unique');
            $table->dropColumn('operational_number');
        });

        Schema::table('order_sequences', function (Blueprint $table): void {
            $table->dropColumn('next_operational_number');
        });
    }
};
