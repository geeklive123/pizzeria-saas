<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_charges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('kitchen_dispatch_id')->nullable()->unique();
            $table->decimal('amount', 16, 2)->default(0);
            $table->boolean('enabled')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'delivery_order_context_fk')->references(['id', 'company_id', 'branch_id'])->on('orders')->restrictOnDelete();
            $table->foreign(['kitchen_dispatch_id', 'company_id', 'branch_id'], 'delivery_dispatch_context_fk')->references(['id', 'company_id', 'branch_id'])->on('kitchen_dispatches')->restrictOnDelete();
        });
        Schema::create('order_delivery_charge_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_delivery_charge_id')->constrained()->restrictOnDelete();
            $table->decimal('previous_amount', 16, 2);
            $table->decimal('new_amount', 16, 2);
            $table->boolean('previous_enabled');
            $table->boolean('new_enabled');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_charge_audits');
        Schema::dropIfExists('order_delivery_charges');
    }
};
