<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DomainException;

final class LegacyManifestTimestamp
{
    public const HISTORICAL_TIMEZONE = 'America/La_Paz';

    private const FORMAT = 'Y-m-d H:i:s';

    public static function historicalToUtc(string $value): string
    {
        return self::toUtc($value, self::HISTORICAL_TIMEZONE);
    }

    public static function databaseTimestampToUtc(?string $value): ?string
    {
        return $value === null ? null : self::toUtc($value, 'UTC');
    }

    public static function toUtc(string $value, string $sourceTimezone): string
    {
        $timestamp = CarbonImmutable::createFromFormat(
            '!'.self::FORMAT,
            $value,
            $sourceTimezone,
        );
        $errors = CarbonImmutable::getLastErrors();

        if ($timestamp === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('Invalid legacy manifest timestamp: '.$value.' ('.$sourceTimezone.').');
        }

        return $timestamp->utc()->format(self::FORMAT);
    }
}
