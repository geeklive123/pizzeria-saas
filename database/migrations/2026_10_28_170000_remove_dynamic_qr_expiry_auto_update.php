<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_qr_intents', function (Blueprint $table): void {
            $table->timestamp('expires_at')->change();
        });
    }

    public function down(): void
    {
        // Restoring automatic changes would corrupt expiry values.
    }
};
