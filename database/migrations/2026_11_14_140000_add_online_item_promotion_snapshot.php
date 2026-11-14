<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_order_request_items', function (Blueprint $table): void {
            $table->boolean('was_promotion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_order_request_items', function (Blueprint $table): void {
            $table->dropColumn('was_promotion');
        });
    }
};
