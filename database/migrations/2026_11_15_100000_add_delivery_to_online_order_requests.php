<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->string('delivery_address', 500)->nullable();
            $table->string('delivery_reference', 500)->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->decimal('delivery_distance_m', 12, 3)->nullable();
            $table->decimal('delivery_origin_latitude', 10, 7)->nullable();
            $table->decimal('delivery_origin_longitude', 10, 7)->nullable();
            $table->unsignedInteger('delivery_radius_m')->nullable();
            $table->string('delivery_coverage', 24)->nullable();
            $table->string('delivery_quote_status', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_order_requests', function (Blueprint $table): void {
            $table->dropColumn(['delivery_address', 'delivery_reference', 'delivery_latitude', 'delivery_longitude', 'delivery_distance_m', 'delivery_origin_latitude', 'delivery_origin_longitude', 'delivery_radius_m', 'delivery_coverage', 'delivery_quote_status']);
        });
    }
};
