<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_qr_inventory_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('online_qr_disposition_id')->unique()->constrained('online_qr_dispositions')->restrictOnDelete();
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->date('action_date');
            $table->string('outcome', 32);
            $table->text('reason');
            $table->text('evidence');
            $table->text('inventory_result');
            $table->dateTime('resolved_at');
        });
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER online_qr_inventory_no_update BEFORE UPDATE ON online_qr_inventory_outcomes BEGIN SELECT RAISE(ABORT, 'Los resultados de inventario son inmutables'); END");
            DB::unprepared("CREATE TRIGGER online_qr_inventory_no_delete BEFORE DELETE ON online_qr_inventory_outcomes BEGIN SELECT RAISE(ABORT, 'Los resultados de inventario son inmutables'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER online_qr_inventory_no_update BEFORE UPDATE ON online_qr_inventory_outcomes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los resultados de inventario son inmutables'");
            DB::unprepared("CREATE TRIGGER online_qr_inventory_no_delete BEFORE DELETE ON online_qr_inventory_outcomes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los resultados de inventario son inmutables'");
        } else {
            throw new RuntimeException('La protección inmutable de inventario no está disponible para este motor.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Los resultados de inventario deben conservarse para auditoría.');
    }
};
