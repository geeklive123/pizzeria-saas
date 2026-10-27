<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Company $company */
        $company = $request->attributes->get('storefrontCompany');
        /** @var Branch $branch */
        $branch = $request->attributes->get('storefrontBranch');

        $domains = config('storefront.domains', []);
        $domainLocation = $domains[strtolower($request->getHost())]['location'] ?? [];
        $location = array_replace(config('storefront.location', []), $domainLocation);
        $phone = $branch->phone ?: $company->phone;
        $demoMode = (bool) config('storefront.demo_mode', true);
        $whatsappNumber = $demoMode ? '' : preg_replace('/\D+/', '', (string) $phone);

        return view('storefront.location', [
            'company' => $company,
            'branch' => $branch,
            'hours' => $location['hours'] ?? [],
            'mapUrl' => $location['map_url'] ?? null,
            'deliveryNote' => $location['delivery_note'] ?? null,
            'phone' => $phone,
            'whatsappUrl' => $whatsappNumber !== '' ? 'https://wa.me/'.$whatsappNumber : null,
            'demoMode' => $demoMode,
        ]);
    }
}
