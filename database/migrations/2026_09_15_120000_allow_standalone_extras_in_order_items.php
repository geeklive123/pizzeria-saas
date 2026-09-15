<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('order_items')->whereNull('product_variant_id')->exists()) {
            throw new RuntimeException('No se puede revertir mientras existan extras independientes históricos.');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable(false)->change();
        });
    }
};
