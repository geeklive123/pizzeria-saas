<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 14, 3)->unsigned();
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_id']);
            $table->index(['company_id', 'recipe_id']);
            $table->index(['company_id', 'ingredient_id']);
            $table->foreign(['recipe_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('recipes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign(['ingredient_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('ingredients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
