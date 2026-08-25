<?php

namespace App\Printing;

final readonly class ThermalDocument
{
    public function __construct(
        public string $bytes,
        public string $plainText,
    ) {}
}
