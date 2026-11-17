<?php

namespace Tests\Visual;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Explicitly invoked only: produces a static Blade fixture, not an application endpoint. */
class RenderCheckoutFixtureTest extends TestCase
{
    public function test_render_checkout_for_local_visual_review(): void
    {
        if (! app()->runningUnitTests() || ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('El render visual requiere pruebas y SQLite en memoria.');
        }
        config(['storefront.demo_mode' => false, 'storefront.delivery_payment_preparation_enabled' => true,
            'storefront.online_dynamic_qr_enabled' => true]);
        $company = new Company(['name' => 'Masa & Maña — prueba visual']);
        $branch = new Branch(['name' => 'Sucursal ficticia', 'address' => 'Dirección de prueba']);
        $html = view('storefront.checkout', ['company' => $company, 'branch' => $branch, 'demoMode' => false,
            'delivery' => ['latitude' => -17.3769946, 'longitude' => -66.1771762, 'free_radius_m' => 800]])->render();
        $directory = base_path('tmp/sprint33-visual');
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo crear el directorio visual aislado.');
        }
        $this->assertNotFalse(file_put_contents($directory.'/checkout.html', $html));
        $this->assertStringContainsString('data-online-qr', $html);
    }
}
