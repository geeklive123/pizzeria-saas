<?php

namespace App\Services;

use App\Models\Branch;
use DomainException;

class DeliveryCoverageService
{
    public function origin(Branch $branch): array
    {
        $origin = config('storefront.delivery.branches.'.$branch->company_id.':'.$branch->id);
        if (! is_array($origin) || ! isset($origin['latitude'], $origin['longitude'], $origin['free_radius_m'])) {
            throw new DomainException('Delivery no está configurado para esta sucursal.');
        }
        [$latitude, $longitude] = $this->coordinates($origin['latitude'], $origin['longitude']);
        if (! is_numeric($origin['free_radius_m']) || (float) $origin['free_radius_m'] <= 0) {
            throw new DomainException('La cobertura de delivery no está configurada.');
        }

        $origin['latitude'] = number_format($latitude, 7, '.', '');
        $origin['longitude'] = number_format($longitude, 7, '.', '');

        return $origin;
    }

    public function calculate(Branch $branch, mixed $latitude, mixed $longitude): array
    {
        [$lat, $lon] = $this->coordinates($latitude, $longitude);
        $origin = $this->origin($branch);
        $lat1 = deg2rad((float) $origin['latitude']);
        $lat2 = deg2rad($lat);
        $dlat = $lat2 - $lat1;
        $dlon = deg2rad($lon - (float) $origin['longitude']);
        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
        $distance = 6371000 * 2 * atan2(sqrt(max(0, min(1, $a))), sqrt(max(0, 1 - $a)));

        return ['distance_m' => round($distance, 3), 'coverage' => $distance <= (float) $origin['free_radius_m'] + 0.000001 ? 'free' : 'outside'];
    }

    private function coordinates(mixed $lat, mixed $lon): array
    {
        if (! is_numeric($lat) || ! is_numeric($lon) || ! is_finite((float) $lat) || ! is_finite((float) $lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            throw new DomainException('La ubicación indicada no es válida.');
        }

        return [(float) $lat, (float) $lon];
    }
}
