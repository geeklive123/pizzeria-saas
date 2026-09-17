<?php

namespace Tests\Unit;

use App\Support\LegacyManifestTimestamp;
use Tests\TestCase;

class LegacyManifestTimestampTest extends TestCase
{
    public function test_bolivia_historical_time_is_the_same_utc_instant_across_the_date_boundary(): void
    {
        $this->assertSame(
            '2026-09-17 01:01:49',
            LegacyManifestTimestamp::historicalToUtc('2026-09-16 21:01:49'),
        );
        $this->assertSame(
            LegacyManifestTimestamp::historicalToUtc('2026-09-16 21:01:49'),
            LegacyManifestTimestamp::databaseTimestampToUtc('2026-09-17 01:01:49'),
        );
    }

    public function test_comparison_remains_exact_to_the_second(): void
    {
        $expected = LegacyManifestTimestamp::historicalToUtc('2026-09-16 21:01:49');

        $this->assertNotSame($expected, LegacyManifestTimestamp::databaseTimestampToUtc('2026-09-17 01:01:50'));
        $this->assertNotSame($expected, LegacyManifestTimestamp::databaseTimestampToUtc('2026-09-17 01:01:48'));
    }

    public function test_same_textual_clock_time_in_a_different_timezone_is_not_the_same_instant(): void
    {
        $this->assertNotSame(
            LegacyManifestTimestamp::toUtc('2026-09-16 21:01:49', 'America/La_Paz'),
            LegacyManifestTimestamp::toUtc('2026-09-16 21:01:49', 'UTC'),
        );
    }

    public function test_operating_system_and_application_timezones_do_not_affect_normalization(): void
    {
        $originalSystemTimezone = date_default_timezone_get();
        $originalApplicationTimezone = config('app.timezone');

        try {
            date_default_timezone_set('Pacific/Auckland');
            config()->set('app.timezone', 'Asia/Tokyo');

            $this->assertSame(
                '2026-09-17 01:01:49',
                LegacyManifestTimestamp::historicalToUtc('2026-09-16 21:01:49'),
            );
            $this->assertSame(
                '2026-09-17 01:01:49',
                LegacyManifestTimestamp::databaseTimestampToUtc('2026-09-17 01:01:49'),
            );
        } finally {
            date_default_timezone_set($originalSystemTimezone);
            config()->set('app.timezone', $originalApplicationTimezone);
        }
    }
}
