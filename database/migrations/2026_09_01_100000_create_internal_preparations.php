<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('output_inventory_item_id');
            $table->unsignedBigInteger('unit_id');
            $table->string('name');
            $table->decimal('theoretical_yield', 16, 3)->unsigned();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->unique(['company_id', 'output_inventory_item_id']);
            $table->index(['company_id', 'is_active']);
            $table->foreign(['output_inventory_item_id', 'company_id'])
                ->references(['id', 'company_id'])->on('inventory_items')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['unit_id', 'company_id'])
                ->references(['id', 'company_id'])->on('units')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('preparation_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('preparation_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->timestamps();

            $table->unique(['preparation_id', 'inventory_item_id']);
            $table->index(['company_id', 'inventory_item_id']);
            $table->foreign(['preparation_id', 'company_id'])
                ->references(['id', 'company_id'])->on('preparations')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'])
                ->references(['id', 'company_id'])->on('inventory_items')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('preparation_productions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('preparation_id');
            $table->unsignedInteger('lots');
            $table->decimal('theoretical_yield', 16, 3)->unsigned();
            $table->decimal('actual_yield', 16, 3)->unsigned();
            $table->decimal('yield_variance', 16, 3);
            $table->decimal('total_cost', 20, 6)->unsigned();
            $table->decimal('unit_cost', 16, 6)->unsigned();
            $table->timestamp('produced_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'branch_id', 'produced_at'], 'prep_productions_branch_date_index');
            $table->foreign(['branch_id', 'company_id'])
                ->references(['id', 'company_id'])->on('branches')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['preparation_id', 'company_id'])
                ->references(['id', 'company_id'])->on('preparations')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_productions');
        Schema::dropIfExists('preparation_components');
        Schema::dropIfExists('preparations');
    }
};
