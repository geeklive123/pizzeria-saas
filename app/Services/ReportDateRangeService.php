<?php

namespace App\Services;

use App\Data\ReportDateRange;
use Carbon\CarbonImmutable;

class ReportDateRangeService
{
    public function from(array $filters): ReportDateRange
    {
        $timezone = config('reports.timezone');
        $now = CarbonImmutable::now($timezone);
        $preset = $filters['preset'] ?? 'today';

        [$from, $to, $label] = match ($preset) {
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay(), 'Ayer'],
            'week' => [$now->startOfWeek()->startOfDay(), $now->endOfDay(), 'Esta semana'],
            'month' => [$now->startOfMonth()->startOfDay(), $now->endOfDay(), 'Este mes'],
            'previous_month' => [$now->subMonthNoOverflow()->startOfMonth()->startOfDay(), $now->subMonthNoOverflow()->endOfMonth()->endOfDay(), 'Mes anterior'],
            'custom' => [
                CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay(),
                CarbonImmutable::parse($filters['date_to'], $timezone)->endOfDay(),
                'Rango personalizado',
            ],
            default => [$now->startOfDay(), $now->endOfDay(), 'Hoy'],
        };

        return new ReportDateRange($from, $to, $preset, $label, $timezone);
    }
}
