<?php

namespace App\Services;

use DomainException;

class LegacyRestoreEnvironmentGuard
{
    public function assertAllowed(bool $allowProduction): string
    {
        if (app()->environment('local')) {
            $this->assertMysqlConnection('pizzeria_saas');

            if (! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
                throw new DomainException('El entorno local debe usar el clon MySQL en localhost.');
            }

            return 'local';
        }

        return $this->assertProductionAllowed($allowProduction);
    }

    private function assertProductionAllowed(bool $allowProduction): string
    {
        if (! app()->environment('production')) {
            throw new DomainException('Este comando solo admite los entornos local o production.');
        }
        if (! $allowProduction) {
            throw new DomainException('Produccion esta bloqueada. Debes indicar explicitamente --allow-production.');
        }

        $expectedDatabase = trim((string) config('legacy_restore.production_database'));
        if ($expectedDatabase === '') {
            throw new DomainException('LEGACY_RESTORE_PRODUCTION_DATABASE debe estar configurada antes de usar este comando en produccion.');
        }
        $expectedHost = trim((string) config('legacy_restore.production_host'));
        if ($expectedHost === '') {
            throw new DomainException('LEGACY_RESTORE_PRODUCTION_HOST debe estar configurado antes de usar este comando en produccion.');
        }

        $this->assertMysqlConnection($expectedDatabase);
        if (config('database.connections.mysql.host') !== $expectedHost) {
            throw new DomainException('El host MySQL activo no coincide con LEGACY_RESTORE_PRODUCTION_HOST.');
        }

        return 'production';
    }

    private function assertMysqlConnection(string $expectedDatabase): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.driver') !== 'mysql'
            || config('database.connections.mysql.database') !== $expectedDatabase) {
            throw new DomainException('La conexion activa debe ser MySQL y usar exactamente la base esperada '.$expectedDatabase.'.');
        }
    }
}
