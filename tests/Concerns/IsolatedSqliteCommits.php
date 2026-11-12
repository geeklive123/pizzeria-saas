<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** A new in-memory database per test, without an enclosing transaction. */
trait IsolatedSqliteCommits
{
    protected function setUpIsolatedSqliteCommits(): void
    {
        $nonce = getenv('REVERSAL_MYSQL_TEST_NONCE');
        if (is_string($nonce) && preg_match('/^[a-f0-9]{12}$/D', $nonce)
            && app()->environment('testing')
            && config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === 'pizzeria_qr_test_'.$nonce
            && config('database.connections.mysql.host') === '127.0.0.1'
            && empty(config('database.connections.mysql.url'))
            && empty(config('database.connections.mysql.unix_socket'))
            && DB::selectOne('SELECT DATABASE() AS db')->db === 'pizzeria_qr_test_'.$nonce
            && DB::table('qr_concurrency_guard')->where('nonce', $nonce)->count() === 1) {
            Http::preventStrayRequests();

            return;
        }

        if (! app()->environment('testing')
            || config('cache.default') !== 'array'
            || config('queue.default') !== 'sync'
            || config('baneco.enabled')
            || config('baneco.webhook_enabled')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || ! empty(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('La prueba exige SQLite aislado en memoria.');
        }

        Http::preventStrayRequests();
        DB::purge('sqlite');
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }
}
