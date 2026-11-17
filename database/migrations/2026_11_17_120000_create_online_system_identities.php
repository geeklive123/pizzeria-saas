<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_system_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['company_id', 'branch_id'], 'online_system_identity_scope_uq');
            $table->unique(['user_id', 'company_id', 'branch_id'], 'online_system_identity_context_uq');
            $table->foreign(['branch_id', 'company_id'], 'online_system_identity_branch_fk')
                ->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Las identidades de sistema deben conservarse para auditoría.');
    }
};
