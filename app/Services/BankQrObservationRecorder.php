<?php

namespace App\Services;

use App\Models\DynamicQrIntent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class BankQrObservationRecorder
{
    /** Called with the intent locked, inside the confirmation transaction. */
    public function record(DynamicQrIntent $intent, string $status, array $payment): void
    {
        try {
            $this->assertInfrastructureReady();
            if ($intent->reconciliation_data !== null && ! DB::table('bank_qr_observations')
                ->where('dynamic_qr_intent_id', $intent->id)->where('kind', 'legacy')->exists()) {
                $this->insert($intent, 'legacy', (string) $intent->bank_status, $intent->reconciliation_data);
            }

            // There is no documented bank event ID. Preserve every observation rather
            // than deduplicating by transactionId or by identical response contents.
            $this->insert($intent, 'observation', $status, $payment);
        } catch (Throwable $exception) {
            Log::warning('baneco_observation_write_failed', ['intent' => $intent->ulid, 'company_id' => $intent->company_id]);
            throw $exception;
        }
    }

    private function assertInfrastructureReady(): void
    {
        $names = ['bank_qr_observations_no_update', 'bank_qr_observations_no_delete'];
        if (! Schema::hasTable('bank_qr_observations') || ! Schema::hasTable('migrations')
            || ! DB::table('migrations')->where('migration', '2026_11_18_200000_create_bank_qr_observations')->exists()) {
            throw new RuntimeException('El historial bancario no está completamente instalado.');
        }
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $count = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'bank_qr_observations')->whereIn('name', $names)->count();
        } elseif ($driver === 'mysql') {
            $engine = DB::table('information_schema.TABLES')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'bank_qr_observations')->value('ENGINE');
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('El historial bancario requiere InnoDB.');
            }
            $count = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('EVENT_OBJECT_TABLE', 'bank_qr_observations')->whereIn('TRIGGER_NAME', $names)->count();
        } else {
            throw new RuntimeException('Motor no compatible con el historial bancario.');
        }
        if ($count !== 2) {
            throw new RuntimeException('El historial bancario no cuenta con todas sus protecciones.');
        }
    }

    private function insert(DynamicQrIntent $intent, string $kind, string $status, array $payment): void
    {
        $safe = [];
        foreach (['qrId', 'amount', 'currency', 'transactionId', 'paymentDate', 'paymentTime'] as $key) {
            if (array_key_exists($key, $payment)) {
                $value = $payment[$key];
                if (is_string($value) && strlen($value) > 512) {
                    $safe[$key] = ['length' => strlen($value), 'sha256' => hash('sha256', $value)];
                } elseif (is_scalar($value) || $value === null) {
                    $safe[$key] = $value;
                } else {
                    $safe[$key] = ['invalid_type' => get_debug_type($value)];
                }
            }
        }
        DB::table('bank_qr_observations')->insert([
            'dynamic_qr_intent_id' => $intent->id,
            'kind' => $kind,
            'evidence' => Crypt::encryptString(json_encode(['status' => $status, 'payment' => $safe], JSON_THROW_ON_ERROR)),
            'observed_at' => now(),
        ]);
    }
}
