<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\SimulatedOnlineBank;
use Tests\TestCase;

class SimulatedOnlineBankTest extends TestCase
{
    public function test_local_bank_simulates_a_late_confirmation_without_network(): void
    {
        $bank = new SimulatedOnlineBank;
        $bank->install();
        $url = 'https://bank.invalid/api/qrsimple/v2/statusQR/bank-fake-1';

        $this->assertSame(0, Http::get($url)->json('statusQrCode'));
        $bank->bankCancelled = true;
        $this->assertSame(9, Http::get($url)->json('statusQrCode'));
        $bank->bankCancelled = false;
        $bank->paidQr = true;
        $this->assertSame(1, Http::get($url)->json('statusQrCode'));
        $this->assertSame('simulated-transaction', Http::get($url)->json('payment.transactionId'));
    }

    public function test_simulator_refuses_production_environment(): void
    {
        $this->app['env'] = 'production';
        try {
            (new SimulatedOnlineBank)->install();
            $this->fail('El simulador no debe instalarse en producci\u00f3n.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SQLite en memoria', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_unexpected_request_is_rejected_without_network(): void
    {
        (new SimulatedOnlineBank)->install();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected simulated bank request.');
        Http::get('https://bank.invalid/unexpected');
    }
}
