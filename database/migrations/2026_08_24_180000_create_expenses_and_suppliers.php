<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('ulid', 'exp_cat_ulid_uq');
            $table->unique(['id', 'company_id'], 'exp_cat_context_uq');
            $table->unique(['company_id', 'name'], 'exp_cat_name_uq');
            $table->index(['company_id', 'is_active'], 'exp_cat_listing_ix');
            $table->foreign('company_id', 'exp_cat_company_fk')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->string('name', 190);
            $table->string('tax_id', 60)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('ulid', 'supplier_ulid_uq');
            $table->unique(['id', 'company_id'], 'supplier_context_uq');
            $table->unique(['company_id', 'name'], 'supplier_name_uq');
            $table->index(['company_id', 'is_active'], 'supplier_listing_ix');
            $table->foreign('company_id', 'supplier_company_fk')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('expense_category_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('description', 500);
            $table->decimal('amount', 16, 2)->unsigned();
            $table->date('expense_date');
            $table->string('document_type', 30);
            $table->string('document_number', 190)->nullable();
            $table->string('payment_method', 20);
            $table->unsignedBigInteger('cash_session_id')->nullable();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by');
            $table->foreignId('approved_by')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'expense_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'expense_context_uq');
            $table->unique('reversal_of_id', 'expense_reversal_uq');
            $table->index(['company_id', 'branch_id', 'expense_date'], 'expense_listing_ix');
            $table->index(['company_id', 'expense_category_id', 'status'], 'expense_category_ix');
            $table->foreign('company_id', 'expense_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'expense_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'expense_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'expense_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['expense_category_id', 'company_id'], 'expense_category_fk')
                ->references(['id', 'company_id'])->on('expense_categories')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['supplier_id', 'company_id'], 'expense_supplier_fk')
                ->references(['id', 'company_id'])->on('suppliers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id', 'branch_id'], 'expense_session_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['reversal_of_id', 'company_id', 'branch_id'], 'expense_reversal_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('expenses')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('expense_categories');
    }
};
