<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_qr_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_order_id')->nullable()->unique();
            $table->foreign(['pos_order_id', 'company_id', 'branch_id'], 'online_qr_order_context_fk')
                ->references(['id', 'company_id', 'branch_id'])->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Los vínculos de operaciones online deben conservarse para auditoría.');
    }
};
