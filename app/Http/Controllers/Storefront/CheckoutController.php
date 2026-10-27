<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\BuildStorefrontWhatsAppOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontCheckoutRequest;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function show(Request $request): View
    {
        return view('storefront.checkout', [
            'company' => $request->attributes->get('storefrontCompany'),
            'branch' => $request->attributes->get('storefrontBranch'),
            'demoMode' => (bool) config('storefront.demo_mode', true),
        ]);
    }

    public function whatsapp(
        StorefrontCheckoutRequest $request,
        BuildStorefrontWhatsAppOrderAction $action,
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('storefrontCompany');
        /** @var Branch $branch */
        $branch = $request->attributes->get('storefrontBranch');

        return response()->json($action->execute($company, $branch, $request->validated()));
    }
}
