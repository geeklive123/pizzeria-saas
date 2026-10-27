<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\StorefrontPromotionCatalogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function __invoke(Request $request, StorefrontPromotionCatalogService $catalog): View
    {
        /** @var Company $company */
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');

        return view('storefront.promotions', [
            'company' => $company,
            'branch' => $branch,
            'promotions' => $catalog->activeFor($company, $branch),
            'demoMode' => (bool) config('storefront.demo_mode', true),
        ]);
    }
}
