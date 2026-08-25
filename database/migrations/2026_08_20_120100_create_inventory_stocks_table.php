<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 16, 3)->default(0);
            $table->decimal('average_cost', 16, 6)->unsigned()->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'inventory_item_id']);
            $table->index(['company_id', 'inventory_item_id']);
            $table->foreign(['branch_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('inventory_items')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
