<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\CreateOnlineOrderRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineOrderRequest;
use App\Models\OnlineOrderRequest as OnlineRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function store(OnlineOrderRequest $request, CreateOnlineOrderRequestAction $action): JsonResponse
    {
        abort_if(config('storefront.demo_mode', true), 403, 'La tienda está en modo demostración.');
        try {
            $order = $action->execute($request->attributes->get('storefrontCompany'), $request->attributes->get('storefrontBranch'), $request->validated());
        } catch (QueryException) {
            return response()->json(['message' => 'No se pudo verificar el pedido. Inténtalo de nuevo.'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['code' => $order->publicCode(), 'subtotal' => $order->subtotal])->header('Cache-Control', 'no-store');
    }

    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        try {
            $order = OnlineRequest::query()->forCompany($request->attributes->get('storefrontCompany'))
                ->where('branch_id', $request->attributes->get('storefrontBranch')->id)
                ->where('idempotency_key', $data['idempotency_key'])->where('is_fence', false)->firstOrFail();
        } catch (QueryException) {
            return response()->json(['message' => 'No se pudo verificar el pedido. Inténtalo de nuevo.'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['code' => $order->publicCode(), 'subtotal' => $order->subtotal])->header('Cache-Control', 'no-store');
    }

    public function resolve(Request $request, CreateOnlineOrderRequestAction $action): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        try {
            $result = $action->resolve($request->attributes->get('storefrontCompany'), $request->attributes->get('storefrontBranch'), $data['idempotency_key']);
        } catch (QueryException) {
            return response()->json(['message' => 'No se pudo verificar el pedido. Inténtalo de nuevo.'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json($result->is_fence ? ['created' => false] : [
            'created' => true, 'code' => $result->publicCode(), 'subtotal' => $result->subtotal,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): View
    {
        return view('storefront.checkout', [
            'company' => $request->attributes->get('storefrontCompany'),
            'branch' => $request->attributes->get('storefrontBranch'),
            'demoMode' => (bool) config('storefront.demo_mode', true),
        ]);
    }
}
