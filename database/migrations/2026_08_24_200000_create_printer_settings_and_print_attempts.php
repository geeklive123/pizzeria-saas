<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_settings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('purpose', 30);
            $table->string('windows_printer_name', 255)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('auto_print')->default(false);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->unsignedTinyInteger('paper_width_mm')->default(80);
            $table->timestamps();

            $table->unique('ulid', 'printer_settings_ulid_uq');
            $table->unique(['id', 'company_id', 'branch_id'], 'printer_settings_context_uq');
            $table->unique(['company_id', 'branch_id', 'purpose'], 'printer_settings_purpose_uq');
            $table->foreign('company_id', 'printer_settings_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'printer_settings_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('print_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid');
            $table->foreignId('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('printer_setting_id')->nullable();
            $table->unsignedBigInteger('kitchen_dispatch_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('purpose', 30);
            $table->string('windows_printer_name', 255)->nullable();
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('status', 20);
            $table->boolean('is_reprint')->default(false);
            $table->foreignId('requested_by')->nullable();
            $table->timestamp('attempted_at');
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->unique('ulid', 'print_attempts_ulid_uq');
            $table->index(['company_id', 'branch_id', 'purpose', 'attempted_at'], 'print_attempts_listing_ix');
            $table->index(['kitchen_dispatch_id', 'attempted_at'], 'print_attempts_dispatch_ix');
            $table->index(['order_id', 'attempted_at'], 'print_attempts_order_ix');
            $table->foreign('company_id', 'print_attempts_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('requested_by', 'print_attempts_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'print_attempts_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['printer_setting_id', 'company_id', 'branch_id'], 'print_attempts_setting_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('printer_settings')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['kitchen_dispatch_id', 'company_id', 'branch_id'], 'print_attempts_dispatch_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('kitchen_dispatches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'branch_id'], 'print_attempts_order_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_attempts');
        Schema::dropIfExists('printer_settings');
    }
};
