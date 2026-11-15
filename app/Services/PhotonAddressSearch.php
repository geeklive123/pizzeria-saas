<?php

namespace App\Services;

use App\Contracts\AddressSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PhotonAddressSearch implements AddressSearch
{
    public function search(string $query, array $origin): array
    {
        $key = 'address-search:'.hash('sha256', mb_strtolower($query).'|'.$origin['latitude'].'|'.$origin['longitude']);

        return Cache::remember($key, now()->addMinutes(5), function () use ($query, $origin): array {
            try {
                $response = Http::timeout(4)->acceptJson()->withHeaders(['User-Agent' => 'MasaManaStorefront/1.0'])->get(config('storefront.delivery.photon_url'), [
                    'q' => $query, 'lat' => $origin['latitude'], 'lon' => $origin['longitude'], 'limit' => 5,
                ]);
                if (! $response->successful()) {
                    throw new RuntimeException('Búsqueda no disponible.');
                }
                $results = [];
                foreach (array_slice($response->json('features', []), 0, 5) as $feature) {
                    $coordinates = data_get($feature, 'geometry.coordinates');
                    if (! is_array($coordinates) || count($coordinates) < 2 || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1]) || abs((float) $coordinates[0]) > 180 || abs((float) $coordinates[1]) > 90) {
                        continue;
                    }
                    $properties = $feature['properties'] ?? [];
                    $parts = array_filter(array_map(fn ($key) => is_string($properties[$key] ?? null) ? trim($properties[$key]) : '', ['name', 'street', 'housenumber', 'city', 'state']));
                    $label = mb_substr(implode(', ', array_unique($parts)), 0, 300);
                    if ($label !== '') {
                        $results[] = ['label' => $label, 'latitude' => (float) $coordinates[1], 'longitude' => (float) $coordinates[0]];
                    }
                }

                return $results;
            } catch (Throwable) {
                throw new RuntimeException('La búsqueda de direcciones no está disponible. Puedes seleccionar el mapa manualmente.');
            }
        });
    }
}
