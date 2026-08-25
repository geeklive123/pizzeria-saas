<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('restaurant_table_id')->nullable();
            $table->unsignedBigInteger('active_restaurant_table_id')->nullable()->unique();
            $table->unsignedBigInteger('order_number');
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 16, 2)->unsigned()->default(0);
            $table->decimal('discount_total', 16, 2)->unsigned()->default(0);
            $table->decimal('total', 16, 2)->unsigned()->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'company_id', 'branch_id'], 'orders_context_unique');
            $table->unique(['company_id', 'branch_id', 'order_number']);
            $table->index(['company_id', 'branch_id', 'status', 'opened_at'], 'orders_open_listing_index');
            $table->foreign(['branch_id', 'company_id'])->references(['id', 'company_id'])
                ->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['restaurant_table_id', 'company_id', 'branch_id'])
                ->references(['id', 'company_id', 'branch_id'])->on('restaurant_tables')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['active_restaurant_table_id', 'company_id', 'branch_id'])
                ->references(['id', 'company_id', 'branch_id'])->on('restaurant_tables')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
