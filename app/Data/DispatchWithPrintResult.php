<?php

namespace App\Data;

use App\Models\KitchenDispatch;
use App\Models\PrintAttempt;

final readonly class DispatchWithPrintResult
{
    public function __construct(
        public ?KitchenDispatch $dispatch,
        public ?PrintAttempt $printAttempt,
    ) {}
}
