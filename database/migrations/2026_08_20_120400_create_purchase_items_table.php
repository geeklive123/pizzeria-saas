<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->unsignedBigInteger('input_unit_id');
            $table->decimal('base_quantity', 16, 3)->unsigned();
            $table->decimal('unit_cost', 16, 6)->unsigned();
            $table->decimal('total_cost', 20, 6)->unsigned();
            $table->timestamps();

            $table->unique(['purchase_id', 'inventory_item_id']);
            $table->index(['company_id', 'inventory_item_id']);
            $table->foreign(['purchase_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('purchases')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('inventory_items')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreign(['input_unit_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
