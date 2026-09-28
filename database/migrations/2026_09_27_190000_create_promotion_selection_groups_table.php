<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_selection_groups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('category_id');
            $table->string('size_key', 80);
            $table->unsignedSmallInteger('quantity');
            $table->boolean('allow_repeated_flavors')->default(false);
            $table->boolean('allow_fusions')->default(false);
            $table->boolean('allow_extras')->default(false);
            $table->boolean('allow_removals')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'psg_company_context_uq');
            $table->index(['promotion_id', 'sort_order'], 'psg_promotion_sort_ix');
            $table->index(['company_id', 'category_id', 'size_key'], 'psg_eligible_variants_ix');
            $table->foreign('company_id', 'psg_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['promotion_id', 'company_id'], 'psg_promotion_company_fk')
                ->references(['id', 'company_id'])->on('promotions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['category_id', 'company_id'], 'psg_category_company_fk')
                ->references(['id', 'company_id'])->on('categories')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_selection_groups');
    }
};
