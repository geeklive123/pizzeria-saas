<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_modifiers', function (Blueprint $table): void {
            $table->string('purpose', 30)->default('order_modifier')->after('product_id');
            $table->index(['company_id', 'purpose', 'is_active'], 'pm_purpose_listing_ix');
        });

        Schema::table('modifier_options', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        Schema::create('modifier_option_size_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('modifier_option_id');
            $table->string('size_key', 80);
            $table->decimal('price_delta', 12, 2)->unsigned()->nullable();
            $table->decimal('quantity', 16, 3)->unsigned()->nullable();
            $table->timestamps();

            $table->unique(['modifier_option_id', 'size_key'], 'mosr_option_size_uq');
            $table->unique(['id', 'company_id'], 'mosr_context_uq');
            $table->index(['company_id', 'size_key'], 'mosr_company_size_ix');
            $table->foreign('company_id', 'mosr_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['modifier_option_id', 'company_id'], 'mosr_option_company_fk')
                ->references(['id', 'company_id'])->on('modifier_options')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_option_size_rules');
        Schema::table('modifier_options', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('product_modifiers', function (Blueprint $table): void {
            $table->dropIndex('pm_purpose_listing_ix');
            $table->dropColumn('purpose');
        });
    }
};
