<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('role', 30);
            $table->string('session_hash', 64);
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamp('logged_out_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('logout_reason', 30)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'logged_in_at']);
            $table->index(['company_id', 'user_id', 'logged_in_at']);
            $table->index(['company_id', 'branch_id', 'logged_in_at']);
            $table->index(['company_id', 'role', 'logged_in_at']);
            $table->index(['company_id', 'logged_out_at']);
            $table->index(['session_hash', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_logs');
    }
};
