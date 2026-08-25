<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->decimal('minimum_quantity', 16, 3)->unsigned()->nullable()->after('average_cost');
            $table->unique(['id', 'company_id'], 'inventory_stocks_id_company_unique');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->date('expires_at')->nullable()->after('total_cost');
            $table->unique(['id', 'company_id'], 'purchase_items_id_company_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropUnique('purchase_items_id_company_unique');
            $table->dropColumn('expires_at');
        });

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropUnique('inventory_stocks_id_company_unique');
            $table->dropColumn('minimum_quantity');
        });
    }
};
