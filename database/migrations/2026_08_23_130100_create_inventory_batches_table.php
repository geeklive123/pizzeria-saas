<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->unsignedBigInteger('purchase_item_id')->nullable()->unique();
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->unique();
            $table->unsignedBigInteger('transition_stock_id')->nullable()->unique();
            $table->decimal('quantity_received', 16, 3)->unsigned();
            $table->decimal('quantity_remaining', 16, 3)->unsigned();
            $table->decimal('unit_cost', 16, 6)->unsigned();
            $table->timestamp('received_at');
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->index(
                ['company_id', 'branch_id', 'inventory_item_id', 'expires_at'],
                'inventory_batches_fefo_index',
            );
            $table->foreign(['branch_id', 'company_id'])
                ->references(['id', 'company_id'])->on('branches')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'])
                ->references(['id', 'company_id'])->on('inventory_items')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['purchase_item_id', 'company_id'])
                ->references(['id', 'company_id'])->on('purchase_items')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['inventory_movement_id', 'company_id'])
                ->references(['id', 'company_id'])->on('inventory_movements')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['transition_stock_id', 'company_id'])
                ->references(['id', 'company_id'])->on('inventory_stocks')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
