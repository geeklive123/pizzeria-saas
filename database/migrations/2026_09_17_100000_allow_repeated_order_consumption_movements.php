<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropUnique('inventory_movements_reference_unique');
            $table->index(
                ['company_id', 'reference_type', 'reference_id', 'type', 'inventory_item_id'],
                'inventory_movements_reference_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inventory_movements_reference_index');
            $table->unique(
                ['company_id', 'reference_type', 'reference_id', 'type', 'inventory_item_id'],
                'inventory_movements_reference_unique',
            );
        });
    }
};
