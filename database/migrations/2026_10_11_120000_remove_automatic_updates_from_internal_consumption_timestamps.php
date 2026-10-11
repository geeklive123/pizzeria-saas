<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_consumptions', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->useCurrent()->change();
        });

        Schema::table('internal_consumption_cancellations', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Reintroducing ON UPDATE CURRENT_TIMESTAMP would corrupt historical audit dates.
    }
};
