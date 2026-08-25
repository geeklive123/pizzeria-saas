<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('size_key', 80)->nullable()->after('name');
            $table->index(['company_id', 'size_key', 'is_active'], 'pv_company_size_ix');
        });

        foreach (DB::table('product_variants')->select('id', 'name')->orderBy('id')->get() as $variant) {
            DB::table('product_variants')->where('id', $variant->id)->update([
                'size_key' => Str::slug((string) $variant->name),
            ]);
        }

        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->string('component_type', 20)->default('topping')->after('ingredient_id');
            $table->index(['recipe_id', 'component_type'], 'recipe_items_component_ix');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('configuration_snapshot')->nullable()->after('notes');
        });

        Schema::create('order_item_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedSmallInteger('fraction_numerator');
            $table->unsignedSmallInteger('fraction_denominator');
            $table->unsignedTinyInteger('position');
            $table->decimal('unit_price_snapshot', 12, 2)->unsigned();
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot');
            $table->timestamps();

            $table->unique(['order_item_id', 'position'], 'ois_item_position_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'ois_context_uq');
            $table->index(['company_id', 'order_item_id'], 'ois_item_ix');
            $table->foreign('company_id', 'ois_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['order_item_id', 'company_id', 'branch_id'], 'ois_item_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('order_items')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'company_id'], 'ois_variant_company_fk')
                ->references(['id', 'company_id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('product_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'pm_context_uq');
            $table->index(['company_id', 'product_id', 'is_active'], 'pm_listing_ix');
            $table->foreign('company_id', 'pm_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['product_id', 'company_id'], 'pm_product_company_fk')
                ->references(['id', 'company_id'])->on('products')->restrictOnDelete();
        });

        Schema::create('modifier_options', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('product_modifier_id');
            $table->string('name');
            $table->string('type', 20);
            $table->decimal('price_delta', 12, 2)->unsigned()->default(0);
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('quantity', 16, 3)->unsigned()->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'mo_context_uq');
            $table->index(['company_id', 'product_modifier_id', 'is_active'], 'mo_listing_ix');
            $table->foreign('company_id', 'mo_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['product_modifier_id', 'company_id'], 'mo_modifier_company_fk')
                ->references(['id', 'company_id'])->on('product_modifiers')->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'], 'mo_inventory_company_fk')
                ->references(['id', 'company_id'])->on('inventory_items')->restrictOnDelete();
            $table->foreign(['ingredient_id', 'company_id'], 'mo_ingredient_company_fk')
                ->references(['id', 'company_id'])->on('ingredients')->restrictOnDelete();
            $table->foreign(['unit_id', 'company_id'], 'mo_unit_company_fk')
                ->references(['id', 'company_id'])->on('units')->restrictOnDelete();
        });

        Schema::create('order_item_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('order_item_section_id')->nullable();
            $table->unsignedBigInteger('modifier_option_id')->nullable();
            $table->string('type', 20);
            $table->string('name_snapshot');
            $table->decimal('price_delta_snapshot', 12, 2)->unsigned()->default(0);
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->decimal('quantity_snapshot', 16, 3)->unsigned()->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'order_item_id'], 'oim_item_ix');
            $table->foreign('company_id', 'oim_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['order_item_id', 'company_id', 'branch_id'], 'oim_item_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('order_items')->restrictOnDelete();
            $table->foreign(['order_item_section_id', 'company_id', 'branch_id'], 'oim_section_ctx_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('order_item_sections')->restrictOnDelete();
            $table->foreign(['modifier_option_id', 'company_id'], 'oim_option_company_fk')
                ->references(['id', 'company_id'])->on('modifier_options')->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'], 'oim_inventory_company_fk')
                ->references(['id', 'company_id'])->on('inventory_items')->restrictOnDelete();
            $table->foreign(['unit_id', 'company_id'], 'oim_unit_company_fk')
                ->references(['id', 'company_id'])->on('units')->restrictOnDelete();
        });

        Schema::create('packaging_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id');
            $table->string('size_key', 80);
            $table->string('fulfillment_type', 20);
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->timestamps();

            $table->unique(['company_id', 'size_key', 'fulfillment_type', 'inventory_item_id'], 'pr_rule_uq');
            $table->index(['company_id', 'size_key', 'fulfillment_type'], 'pr_lookup_ix');
            $table->foreign('company_id', 'pr_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'], 'pr_inventory_company_fk')
                ->references(['id', 'company_id'])->on('inventory_items')->restrictOnDelete();
        });

        DB::table('recipe_items')
            ->whereIn('ingredient_id', DB::table('ingredients')->whereIn('name', ['Masa', 'Salsa de tomate', 'Mozzarella'])->select('id'))
            ->whereIn('recipe_id', DB::table('recipes')->whereIn('product_variant_id', DB::table('product_variants')
                ->whereIn('product_id', DB::table('products')->where('type', 'pizza')->select('id'))->select('id'))->select('id'))
            ->update(['component_type' => 'base']);

        $packagingRows = DB::table('recipe_items')
            ->join('ingredients', 'ingredients.id', '=', 'recipe_items.ingredient_id')
            ->join('inventory_items', 'inventory_items.ingredient_id', '=', 'ingredients.id')
            ->join('recipes', 'recipes.id', '=', 'recipe_items.recipe_id')
            ->join('product_variants', 'product_variants.id', '=', 'recipes.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.type', 'pizza')
            ->where('ingredients.name', 'like', 'Caja %')
            ->select('recipe_items.id', 'recipe_items.company_id', 'recipe_items.quantity', 'inventory_items.id as inventory_item_id', 'product_variants.size_key')
            ->get();

        foreach ($packagingRows as $row) {
            DB::table('packaging_rules')->updateOrInsert([
                'company_id' => $row->company_id,
                'size_key' => $row->size_key,
                'fulfillment_type' => 'takeaway',
                'inventory_item_id' => $row->inventory_item_id,
            ], [
                'ulid' => (string) Str::ulid(),
                'quantity' => $row->quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('recipe_items')->whereIn('id', $packagingRows->pluck('id'))->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_rules');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('product_modifiers');
        Schema::dropIfExists('order_item_sections');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('configuration_snapshot');
        });
        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->dropIndex('recipe_items_component_ix');
            $table->dropColumn('component_type');
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('pv_company_size_ix');
            $table->dropColumn('size_key');
        });
    }
};
