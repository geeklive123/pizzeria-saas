<?php

namespace App\Http\Controllers\Storefront;

use App\Contracts\AddressSearch;
use App\Http\Controllers\Controller;
use App\Services\DeliveryCoverageService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeliveryController extends Controller
{
    public function search(Request $request, AddressSearch $search, DeliveryCoverageService $coverage): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'min:3', 'max:120']]);
        try {
            $results = $search->search(trim($data['query']), $coverage->origin($request->attributes->get('storefrontBranch')));
        } catch (DomainException|RuntimeException) {
            return response()->json(['message' => 'La búsqueda no está disponible. Selecciona el punto en el mapa.'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['results' => $results])->header('Cache-Control', 'no-store');
    }

    public function coverage(Request $request, DeliveryCoverageService $coverage): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180']]);
        try {
            $result = $coverage->calculate($request->attributes->get('storefrontBranch'),
                number_format((float) $data['latitude'], 7, '.', ''), number_format((float) $data['longitude'], 7, '.', ''));
        } catch (DomainException) {
            return response()->json(['message' => 'No se pudo calcular la cobertura.'], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
