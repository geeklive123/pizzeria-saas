<?php

namespace Tests\Concerns;

use Database\Seeders\MasaYManaMenuSeeder;

trait SeedsMasaYManaMenuFixture
{
    protected function seedMasaYManaMenuFixture(): void
    {
        $basePath = $this->app->basePath();
        $this->app->setBasePath(__DIR__.'/../Fixtures');

        try {
            $this->seed(MasaYManaMenuSeeder::class);
        } finally {
            $this->app->setBasePath($basePath);
        }
    }
}
