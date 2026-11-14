<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\BuildStorefrontWhatsAppOrderAction;
use App\Actions\CreateOnlineOrderRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineOrderRequest;
use App\Http\Requests\StorefrontCheckoutRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OnlineOrderRequest as OnlineRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function store(OnlineOrderRequest $request, CreateOnlineOrderRequestAction $action): JsonResponse
    {
        abort_if(config('storefront.demo_mode', true), 403, 'La tienda está en modo demostración.');
        $order = $action->execute($request->attributes->get('storefrontCompany'), $request->attributes->get('storefrontBranch'), $request->validated());

        return response()->json(['code' => $order->publicCode(), 'subtotal' => $order->subtotal])->header('Cache-Control', 'no-store');
    }

    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        $order = OnlineRequest::query()->forCompany($request->attributes->get('storefrontCompany'))
            ->where('branch_id', $request->attributes->get('storefrontBranch')->id)
            ->where('idempotency_key', $data['idempotency_key'])->firstOrFail();

        return response()->json(['code' => $order->publicCode(), 'subtotal' => $order->subtotal])->header('Cache-Control', 'no-store');
    }

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
