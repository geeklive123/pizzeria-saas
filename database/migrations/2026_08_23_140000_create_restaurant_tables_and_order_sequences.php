<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 50);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'company_id', 'branch_id'], 'restaurant_tables_context_unique');
            $table->unique(['company_id', 'branch_id', 'name']);
            $table->index(['company_id', 'branch_id', 'is_active', 'sort_order'], 'restaurant_tables_listing_index');
            $table->foreign(['branch_id', 'company_id'])->references(['id', 'company_id'])
                ->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('order_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'branch_id']);
            $table->foreign(['branch_id', 'company_id'])->references(['id', 'company_id'])
                ->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sequences');
        Schema::dropIfExists('restaurant_tables');
    }
};
