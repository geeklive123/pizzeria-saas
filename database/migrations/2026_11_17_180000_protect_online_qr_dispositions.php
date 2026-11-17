<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER online_qr_dispositions_no_update BEFORE UPDATE ON online_qr_dispositions BEGIN SELECT RAISE(ABORT, 'Las disposiciones son inmutables'); END");
            DB::unprepared("CREATE TRIGGER online_qr_dispositions_no_delete BEFORE DELETE ON online_qr_dispositions BEGIN SELECT RAISE(ABORT, 'Las disposiciones son inmutables'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER online_qr_dispositions_no_update BEFORE UPDATE ON online_qr_dispositions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las disposiciones son inmutables'");
            DB::unprepared("CREATE TRIGGER online_qr_dispositions_no_delete BEFORE DELETE ON online_qr_dispositions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las disposiciones son inmutables'");
        } else {
            throw new RuntimeException('La protección inmutable de disposiciones no está disponible para este motor.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Las disposiciones financieras deben conservarse para auditoría.');
    }
};
