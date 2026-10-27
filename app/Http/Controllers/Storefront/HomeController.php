<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontPromotionCatalogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        StorefrontCatalogService $catalog,
        StorefrontPromotionCatalogService $promotionCatalog,
    ): View {
        /** @var Company $company */
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        $storefrontCatalog = $catalog->catalog($company, $branch);
        $featuredProducts = $storefrontCatalog['categories']
            ->flatMap(fn ($category) => $category->products)
            ->filter(fn ($product): bool => $product->type === ProductType::Pizza)
            ->sortBy('name')
            ->take(6)
            ->values();

        return view('storefront.home', [
            'company' => $company,
            'branch' => $branch,
            'featuredProducts' => $featuredProducts,
            'featuredPromotions' => $promotionCatalog->activeFor($company, $branch, 3),
            'variantAvailability' => $storefrontCatalog['variantAvailability'],
            'demoMode' => (bool) config('storefront.demo_mode', true),
        ]);
    }
}
