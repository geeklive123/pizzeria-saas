<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique('ulid', 'promotions_ulid_uq');
            $table->unique(['id', 'company_id'], 'promotions_company_context_uq');
            $table->unique(['product_variant_id', 'company_id'], 'promotions_variant_company_uq');
            $table->index(['company_id', 'is_active', 'starts_at', 'ends_at'], 'promotions_active_window_ix');
            $table->foreign('company_id', 'promotions_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'company_id'], 'promotions_variant_company_fk')
                ->references(['id', 'company_id'])->on('product_variants')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('promotion_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->timestamps();
            $table->unique(['promotion_id', 'inventory_item_id'], 'promotion_components_item_uq');
            $table->index(['company_id', 'inventory_item_id'], 'promotion_components_inventory_ix');
            $table->foreign('company_id', 'promotion_components_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['promotion_id', 'company_id'], 'promotion_components_promotion_fk')
                ->references(['id', 'company_id'])->on('promotions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'], 'promotion_components_inventory_fk')
                ->references(['id', 'company_id'])->on('inventory_items')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('promotion_id')->nullable()->after('product_variant_id');
            $table->index(['company_id', 'promotion_id'], 'order_items_promotion_ix');
            $table->foreign(['promotion_id', 'company_id'], 'order_items_promotion_company_fk')
                ->references(['id', 'company_id'])->on('promotions')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign('order_items_promotion_company_fk');
            $table->dropIndex('order_items_promotion_ix');
            $table->dropColumn('promotion_id');
        });
        Schema::dropIfExists('promotion_components');
        Schema::dropIfExists('promotions');
    }
};
