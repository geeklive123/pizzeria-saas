<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->string('type', 30);
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->decimal('unit_cost', 16, 6)->unsigned()->nullable();
            $table->decimal('total_cost', 20, 6)->unsigned()->nullable();
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->index(
                ['company_id', 'branch_id', 'inventory_item_id', 'occurred_at'],
                'inventory_movements_stock_occurred_index',
            );
            $table->index(['company_id', 'type', 'occurred_at']);
            $table->unique(
                ['company_id', 'reference_type', 'reference_id', 'type', 'inventory_item_id'],
                'inventory_movements_reference_unique',
            );
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
            $table->foreign(['reversal_of_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('inventory_movements')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
