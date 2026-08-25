<?php

namespace App\Data;

use Carbon\CarbonImmutable;

readonly class ReportDateRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $preset,
        public string $label,
        public string $timezone,
    ) {}

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    public function query(): array
    {
        return [
            'preset' => $this->preset,
            'date_from' => $this->from->toDateString(),
            'date_to' => $this->to->toDateString(),
        ];
    }
}
