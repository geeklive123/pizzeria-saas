<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\CreateOnlineOrderRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineOrderRequest as OrderInput;
use App\Models\OnlineOrderRequest;
use App\Services\CreatePendingOnlineOrder;
use App\Services\CurrentCashSessionService;
use App\Services\EmitOnlineDynamicQr;
use App\Services\OnlinePaymentPreparationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentPreparationController extends Controller
{
    public function qrStatus(Request $request): JsonResponse
    {
        abort_if($request->query->count() !== 0, 422);
        abort_if(array_diff(array_keys($request->all()), ['idempotency_key']) !== [], 422);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        // A UUID is a bearer capability. Verify the entire persisted context in
        // one query and never turn a public request into a bank API call.
        try {
            $intent = DB::table('online_order_requests as request')
                ->join('online_qr_operations as operation', 'operation.online_order_request_id', '=', 'request.id')
                ->join('orders as pos', 'pos.id', '=', 'operation.pos_order_id')
                ->join('cash_sessions as cash', 'cash.id', '=', 'operation.cash_session_id')
                ->join('online_system_identities as identity', 'identity.user_id', '=', 'operation.system_user_id')
                ->join('dynamic_qr_intents as intent', 'intent.id', '=', 'operation.dynamic_qr_intent_id')
                ->join('bank_qr_settings as setting', 'setting.id', '=', 'intent.bank_qr_setting_id')
                ->leftJoin('payments as payment', function ($join): void {
                    $join->on('payment.id', '=', 'intent.payment_id')
                        ->on('payment.company_id', '=', 'intent.company_id')->on('payment.branch_id', '=', 'intent.branch_id')
                        ->on('payment.order_id', '=', 'intent.order_id')->on('payment.cash_session_id', '=', 'intent.cash_session_id')
                        ->where('payment.status', '=', 'completed')->where('payment.method', '=', 'qr');
                })
                ->where('request.company_id', $company->id)->where('request.branch_id', $branch->id)
                ->where('request.idempotency_key', $data['idempotency_key'])->where('request.is_fence', false)
                ->where('request.fulfillment', 'delivery')
                ->whereColumn('operation.company_id', 'request.company_id')->whereColumn('operation.branch_id', 'request.branch_id')
                ->whereColumn('request.pos_order_id', 'operation.pos_order_id')->whereColumn('request.preparation_cash_session_id', 'operation.cash_session_id')
                ->whereColumn('pos.company_id', 'request.company_id')->whereColumn('pos.branch_id', 'request.branch_id')
                ->whereColumn('pos.created_by', 'operation.system_user_id')
                ->whereColumn('cash.company_id', 'request.company_id')->whereColumn('cash.branch_id', 'request.branch_id')
                ->whereColumn('cash.opened_by', 'operation.original_cashier_id')
                ->whereColumn('identity.company_id', 'request.company_id')->whereColumn('identity.branch_id', 'request.branch_id')
                ->whereColumn('intent.company_id', 'request.company_id')->whereColumn('intent.branch_id', 'request.branch_id')
                ->whereColumn('intent.order_id', 'operation.pos_order_id')->whereColumn('intent.cash_session_id', 'operation.cash_session_id')
                ->whereColumn('intent.user_id', 'operation.system_user_id')->whereColumn('setting.company_id', 'request.company_id')
                ->where(fn ($query) => $query->whereNull('setting.branch_id')->orWhereColumn('setting.branch_id', 'request.branch_id'))
                ->select('intent.status')->selectRaw('CASE WHEN payment.id IS NULL THEN 0 ELSE 1 END AS paid_verified')->first();
        } catch (QueryException) {
            return $this->unavailable();
        }
        abort_unless($intent && in_array($intent->status, ['creating', 'pending', 'paid', 'failed', 'generation_unknown', 'cancelled', 'reconciliation_required'], true), 404);

        $publicStatus = match ($intent->status) {
            'creating', 'pending' => 'pending',
            'paid' => $intent->paid_verified ? 'paid' : 'review',
            default => 'review',
        };

        return response()->json(['status' => $publicStatus])->header('Cache-Control', 'no-store');
    }

    public function qr(Request $request, EmitOnlineDynamicQr $emitter): JsonResponse
    {
        abort_unless(config('storefront.delivery_payment_preparation_enabled') && config('storefront.online_dynamic_qr_enabled'), 404);
        abort_if(config('storefront.demo_mode', true), 403);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        $preparation = OnlineOrderRequest::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('idempotency_key', $data['idempotency_key'])->where('is_fence', false)->firstOrFail();
        try {
            $intent = $emitter->execute($company, $branch, $preparation);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['qr' => $exception->getMessage()]);
        } catch (QueryException) {
            return $this->unavailable();
        }

        return response()->json(['status' => $intent->status->value, 'qr_image' => $intent->qr_image,
            'amount' => $intent->amount, 'expires_at' => $intent->expires_at->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    public function order(Request $request, CreatePendingOnlineOrder $orders): JsonResponse
    {
        abort_unless(config('storefront.delivery_payment_preparation_enabled') && config('storefront.online_dynamic_qr_enabled'), 404);
        abort_if(config('storefront.demo_mode', true), 403);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid:4']]);
        $company = $request->attributes->get('storefrontCompany');
        $branch = $request->attributes->get('storefrontBranch');
        $preparation = OnlineOrderRequest::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('idempotency_key', $data['idempotency_key'])->where('is_fence', false)->firstOrFail();
        try {
            $orders->execute($company, $branch, $preparation);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['order' => $exception->getMessage()]);
        } catch (QueryException) {
            return $this->unavailable();
        }

        return response()->json(['ready' => true])->header('Cache-Control', 'no-store');
    }

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
                abort_if($preparation->pos_order_id !== null, 409, 'Este pedido requiere revisión antes de cancelarlo.');
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
