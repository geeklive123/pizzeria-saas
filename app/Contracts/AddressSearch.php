<?php

namespace App\Contracts;

interface AddressSearch
{
    public function search(string $query, array $origin): array;
}
