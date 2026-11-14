<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->string('legacy_review_resolution', 40)->nullable();
            $table->string('legacy_review_note', 500)->nullable();
            $table->foreignId('legacy_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('legacy_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_reviewed_by');
            $table->dropColumn(['legacy_review_resolution', 'legacy_review_note', 'legacy_reviewed_at']);
        });
    }
};
