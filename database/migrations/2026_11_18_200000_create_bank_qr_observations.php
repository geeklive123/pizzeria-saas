<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_qr_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dynamic_qr_intent_id')->constrained('dynamic_qr_intents')->restrictOnDelete();
            $table->string('kind', 16);
            $table->longText('evidence');
            $table->dateTime('observed_at');
            $table->index(['dynamic_qr_intent_id', 'kind']);
        });
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER bank_qr_observations_no_update BEFORE UPDATE ON bank_qr_observations BEGIN SELECT RAISE(ABORT, 'Las observaciones bancarias son inmutables'); END");
            DB::unprepared("CREATE TRIGGER bank_qr_observations_no_delete BEFORE DELETE ON bank_qr_observations BEGIN SELECT RAISE(ABORT, 'Las observaciones bancarias son inmutables'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER bank_qr_observations_no_update BEFORE UPDATE ON bank_qr_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las observaciones bancarias son inmutables'");
            DB::unprepared("CREATE TRIGGER bank_qr_observations_no_delete BEFORE DELETE ON bank_qr_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las observaciones bancarias son inmutables'");
        } else {
            throw new RuntimeException('La protección de observaciones bancarias no está disponible para este motor.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Las observaciones bancarias deben conservarse para auditoría.');
    }
};
