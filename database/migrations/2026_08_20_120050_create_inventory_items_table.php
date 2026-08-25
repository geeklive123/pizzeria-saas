<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_items')) {
            if (! Schema::hasColumns('inventory_items', [
                'id',
                'ulid',
                'company_id',
                'unit_id',
                'ingredient_id',
                'product_variant_id',
                'name',
                'is_active',
                'created_at',
                'updated_at',
            ])) {
                throw new RuntimeException(
                    'The existing inventory_items table is incomplete. Inspect it before continuing the migration.',
                );
            }

            return;
        }

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('ingredient_id')->nullable()->unique();
            $table->unsignedBigInteger('product_variant_id')->nullable()->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
            $table->foreign(['unit_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreign(['ingredient_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('ingredients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreign(['product_variant_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('product_variants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
