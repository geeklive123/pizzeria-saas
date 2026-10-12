<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('code', 40);
            $table->string('reason', 30);
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->decimal('total_cost', 20, 6)->default(0);
            $table->string('cost_status', 20)->default('known');
            $table->string('idempotency_key', 80);
            $table->foreignId('created_by')->nullable();
            $table->timestamp('confirmed_at')->useCurrent();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'internal_consumptions_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'internal_consumptions_context_uq');
            $table->unique(['company_id', 'branch_id', 'code'], 'internal_consumptions_code_uq');
            $table->unique(['company_id', 'branch_id', 'idempotency_key'], 'internal_consumptions_idempotency_uq');
            $table->index(['company_id', 'branch_id', 'confirmed_at'], 'internal_consumptions_listing_ix');
            $table->foreign('company_id', 'internal_consumptions_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'internal_consumptions_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'internal_consumptions_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('internal_consumption_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('internal_consumption_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->string('fulfillment_type', 20);
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot');
            $table->json('configuration_snapshot');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id', 'branch_id'], 'internal_consumption_items_context_uq');
            $table->index(['internal_consumption_id', 'id'], 'internal_consumption_items_parent_ix');
            $table->foreign(['internal_consumption_id', 'company_id', 'branch_id'], 'internal_consumption_items_parent_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('internal_consumptions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['product_variant_id', 'company_id'], 'internal_consumption_items_variant_fk')
                ->references(['id', 'company_id'])->on('product_variants')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('internal_consumption_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('internal_consumption_id');
            $table->unsignedBigInteger('internal_consumption_item_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('inventory_movement_id');
            $table->string('inventory_item_name_snapshot');
            $table->string('unit_symbol_snapshot', 30);
            $table->decimal('quantity', 16, 3)->unsigned();
            $table->decimal('unit_cost', 16, 6)->unsigned()->nullable();
            $table->decimal('total_cost', 20, 6)->unsigned()->nullable();
            $table->string('cost_status', 20);
            $table->timestamps();

            $table->unique(['internal_consumption_item_id', 'inventory_item_id'], 'internal_consumption_components_item_uq');
            $table->unique('inventory_movement_id', 'internal_consumption_components_movement_uq');
            $table->index(['internal_consumption_id', 'inventory_item_id'], 'internal_consumption_components_parent_ix');
            $table->foreign(['internal_consumption_id', 'company_id', 'branch_id'], 'internal_consumption_components_parent_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('internal_consumptions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['internal_consumption_item_id', 'company_id', 'branch_id'], 'internal_consumption_components_item_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('internal_consumption_items')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'company_id'], 'internal_consumption_components_inventory_fk')
                ->references(['id', 'company_id'])->on('inventory_items')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['unit_id', 'company_id'], 'internal_consumption_components_unit_fk')
                ->references(['id', 'company_id'])->on('units')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['inventory_movement_id', 'company_id'], 'internal_consumption_components_movement_fk')
                ->references(['id', 'company_id'])->on('inventory_movements')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('internal_consumption_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('internal_consumption_id');
            $table->text('reason');
            $table->foreignId('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->useCurrent();
            $table->timestamps();

            $table->unique('internal_consumption_id', 'internal_consumption_cancellations_parent_uq');
            $table->foreign(['internal_consumption_id', 'company_id', 'branch_id'], 'internal_consumption_cancellations_parent_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('internal_consumptions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('cancelled_by', 'internal_consumption_cancellations_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('print_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('internal_consumption_id')->nullable();
            $table->index(['internal_consumption_id', 'attempted_at'], 'print_attempts_internal_consumption_ix');
            $table->foreign(['internal_consumption_id', 'company_id', 'branch_id'], 'print_attempts_internal_consumption_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('internal_consumptions')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('print_attempts', function (Blueprint $table): void {
            $table->dropForeign('print_attempts_internal_consumption_fk');
            $table->dropIndex('print_attempts_internal_consumption_ix');
            $table->dropColumn('internal_consumption_id');
        });
        Schema::dropIfExists('internal_consumption_cancellations');
        Schema::dropIfExists('internal_consumption_components');
        Schema::dropIfExists('internal_consumption_items');
        Schema::dropIfExists('internal_consumptions');
    }
};
