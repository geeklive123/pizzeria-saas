<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Test-only responses. Never register this adapter in the application container. */
final class SimulatedOnlineBank
{
    public int $generations = 0;

    public bool $timeout = false;

    public bool $authFail = false;

    public bool $paidQr = false;

    public bool $bankCancelled = false;

    public string $paidBankId = 'bank-fake-1';

    public function install(): void
    {
        if (! app()->runningUnitTests() || ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('El banco simulado exige pruebas y SQLite en memoria.');
        }

        Http::allowStrayRequests([]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'authentication/encrypt')) {
                return Http::response('"encrypted"');
            }
            if (str_contains($request->url(), 'authentication/authenticate')) {
                return $this->authFail ? Http::response(['responseCode' => 5], 401) : Http::response(['responseCode' => 0, 'token' => 'fake-token']);
            }
            if (str_contains($request->url(), 'generateQR')) {
                $this->generations++;
                if ($this->timeout) {
                    return Http::failedConnection();
                }

                return Http::response(['responseCode' => 0, 'qrId' => 'bank-fake-'.$this->generations, 'qrImage' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=']);
            }
            if (str_contains($request->url(), 'statusQR')) {
                return Http::response(['responseCode' => 0, 'statusQrCode' => $this->bankCancelled ? 9 : ($this->paidQr ? 1 : 0),
                    'payment' => ['qrId' => $this->paidBankId, 'amount' => '24.00', 'currency' => 'BOB', 'transactionId' => 'simulated-transaction']]);
            }
            throw new RuntimeException('Unexpected simulated bank request.');
        });
    }
}
