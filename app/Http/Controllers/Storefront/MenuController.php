<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\StorefrontCatalogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function __invoke(Request $request, StorefrontCatalogService $catalog): View
    {
        /** @var Company $company */
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        $storefrontCatalog = $catalog->catalog($company, $branch);

        return view('storefront.menu', [
            'company' => $company,
            'branch' => $branch,
            'categories' => $storefrontCatalog['categories'],
            'variantAvailability' => $storefrontCatalog['variantAvailability'],
            'demoMode' => (bool) config('storefront.demo_mode', true),
        ]);
    }
}
