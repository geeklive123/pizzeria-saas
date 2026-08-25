<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE inventory_items
                ADD CONSTRAINT inventory_items_exactly_one_source_check
                CHECK (
                    (ingredient_id IS NOT NULL AND product_variant_id IS NULL)
                    OR
                    (ingredient_id IS NULL AND product_variant_id IS NOT NULL)
                )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
