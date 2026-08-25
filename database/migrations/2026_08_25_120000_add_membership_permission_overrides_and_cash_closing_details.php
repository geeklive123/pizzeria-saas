<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'membership_company_context_uq');
        });

        Schema::create('membership_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('membership_id');
            $table->string('permission', 80);
            $table->boolean('allowed');
            $table->timestamps();

            $table->unique(['membership_id', 'permission'], 'membership_permission_override_uq');
            $table->index(['company_id', 'permission', 'allowed'], 'membership_permission_company_ix');
            $table->foreign('company_id', 'membership_permission_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['membership_id', 'company_id'], 'membership_permission_membership_fk')
                ->references(['id', 'company_id'])->on('memberships')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->string('closing_balance_status', 20)->nullable()->after('difference_amount');
            $table->text('closing_observation')->nullable()->after('notes');
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->decimal('resulting_balance_amount', 16, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropColumn('resulting_balance_amount');
        });

        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropColumn(['closing_balance_status', 'closing_observation']);
        });

        Schema::dropIfExists('membership_permission_overrides');

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('membership_company_context_uq');
        });
    }
};
