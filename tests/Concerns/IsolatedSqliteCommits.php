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
