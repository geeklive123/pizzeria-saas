<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->decimal('inherited_cash_amount', 16, 2)->unsigned()->nullable()->after('opening_amount');
            $table->decimal('opening_difference_amount', 16, 2)->nullable()->after('inherited_cash_amount');
            $table->unsignedBigInteger('previous_cash_session_id')->nullable()->after('cash_register_id');

            $table->foreign(['previous_cash_session_id', 'company_id', 'branch_id'], 'cash_session_previous_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->text('observation')->nullable()->after('reason');
            $table->foreignId('authorized_by')->nullable()->after('created_by');
            $table->string('idempotency_key', 64)->nullable()->after('reversal_of_id');

            $table->unique(['company_id', 'idempotency_key'], 'cash_move_idempotency_uq');
            $table->foreign('authorized_by', 'cash_move_authorized_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropForeign('cash_move_authorized_by_fk');
            $table->dropUnique('cash_move_idempotency_uq');
            $table->dropColumn(['observation', 'authorized_by', 'idempotency_key']);
        });

        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropForeign('cash_session_previous_fk');
            $table->dropColumn(['inherited_cash_amount', 'opening_difference_amount', 'previous_cash_session_id']);
        });
    }
};
