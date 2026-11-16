<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\CreateOnlineOrderRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineOrderRequest as OrderInput;
use App\Models\OnlineOrderRequest;
use App\Services\CurrentCashSessionService;
use App\Services\OnlinePaymentPreparationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentPreparationController extends Controller
{
    public function availability(Request $request, CurrentCashSessionService $cash): JsonResponse
    {
        abort_unless(config('storefront.delivery_payment_preparation_enabled'), 404);

        try {
            $open = $cash->forBranch($request->attributes->get('storefrontCompany'), $request->attributes->get('storefrontBranch')) !== null;
        } catch (QueryException) {
            return $this->unavailable();
        }

        return response()->json(['open' => $open])->header('Cache-Control', 'no-store');
    }

    public function store(OrderInput $request, CreateOnlineOrderRequestAction $create, OnlinePaymentPreparationService $readiness): JsonResponse
    {
        abort_unless(config('storefront.delivery_payment_preparation_enabled'), 404);
        abort_if(config('storefront.demo_mode', true), 403);
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        try {
            $preparation = $create->execute($company, $branch, $request->validated(), true);
            $readiness->assertUsable($company, $branch, $preparation);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['location_confirmed' => $exception->getMessage()]);
        } catch (QueryException) {
            return $this->unavailable();
        }

        return $this->summary($preparation);
    }

    public function resolve(Request $request, CreateOnlineOrderRequestAction $create, OnlinePaymentPreparationService $readiness): JsonResponse
    {
        abort_unless(config('storefront.delivery_payment_preparation_enabled'), 404);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        try {
            $preparation = $create->resolve($company, $branch, $data['idempotency_key']);
        } catch (QueryException) {
            return $this->unavailable();
        }
        if ($preparation->is_fence) {
            return response()->json(['created' => false])->header('Cache-Control', 'no-store');
        }
        abort_unless(in_array($preparation->status, ['payment_preparation', 'preparation_cancelled', 'preparation_invalidated'], true), 404);
        try {
            $readiness->assertUsable($company, $branch, $preparation);
        } catch (ValidationException $exception) {
            return response()->json(['created' => true, 'ready' => false, 'message' => collect($exception->errors())->flatten()->first()])->header('Cache-Control', 'no-store');
        } catch (QueryException) {
            return $this->unavailable();
        }

        return $this->summary($preparation);
    }

    public function cancel(Request $request): JsonResponse
    {
        abort_unless(config('storefront.delivery_payment_preparation_enabled'), 404);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        try {
            DB::transaction(function () use ($request, $data): void {
                $preparation = OnlineOrderRequest::query()->forCompany($request->attributes->get('storefrontCompany'))
                    ->where('branch_id', $request->attributes->get('storefrontBranch')->id)
                    ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->firstOrFail();
                abort_unless(in_array($preparation->status, ['payment_preparation', 'preparation_cancelled', 'preparation_invalidated'], true), 404);
                if ($preparation->status !== 'preparation_cancelled') {
                    $preparation->update(['status' => 'preparation_cancelled']);
                }
            }, 3);
        } catch (QueryException) {
            return $this->unavailable();
        }

        return response()->json(['cancelled' => true])->header('Cache-Control', 'no-store');
    }

    private function summary(OnlineOrderRequest $preparation): JsonResponse
    {
        return response()->json(['created' => true, 'ready' => true, 'subtotal' => $preparation->subtotal,
            'expires_at' => $preparation->preparation_expires_at->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'No se pudo verificar la preparación. Inténtalo nuevamente.'], 503)->header('Cache-Control', 'no-store');
    }
}
