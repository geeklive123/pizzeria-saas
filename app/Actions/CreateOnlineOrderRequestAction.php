<?php

namespace App\Actions;

use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OnlineOrderRequest;
use App\Services\DeliveryCoverageService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOnlineOrderRequestAction
{
    public function __construct(private readonly BuildStorefrontWhatsAppOrderAction $catalog, private readonly DeliveryCoverageService $coverage) {}

    public function execute(Company $company, Branch $branch, array $data): OnlineOrderRequest
    {
        $items = collect($data['items'])->sortBy('variant_ulid')->values();
        $canonical = [
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'notes' => $data['notes'] ?? null,
            'fulfillment' => 'pickup',
            'items' => $items->map(fn ($item) => ['variant_ulid' => $item['variant_ulid'], 'quantity' => (int) $item['quantity']])->all(),
        ];
        $delivery = [];
        if (($data['fulfillment'] ?? 'pickup') === 'delivery') {
            $latitude = number_format((float) $data['latitude'], 7, '.', '');
            $longitude = number_format((float) $data['longitude'], 7, '.', '');
            $canonical['fulfillment'] = 'delivery';
            $canonical['delivery'] = ['address' => $data['address'], 'reference' => $data['reference'] ?? null, 'latitude' => $latitude, 'longitude' => $longitude];
        }
        $hash = hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $existing = $this->find($company, $branch, $data['idempotency_key']);
        if ($existing) {
            return $this->assertSameRequest($existing, $hash);
        }
        if ($canonical['fulfillment'] === 'delivery') {
            $result = $this->coverage->calculate($branch, $latitude, $longitude);
            if ($result['coverage'] !== 'free') {
                throw ValidationException::withMessages(['location_confirmed' => 'La ubicación está fuera de cobertura. Cambia el punto o elige recojo en local.']);
            }
            $origin = $this->coverage->origin($branch);
            $delivery = ['delivery_address' => $data['address'], 'delivery_reference' => $data['reference'] ?? null,
                'delivery_latitude' => $latitude, 'delivery_longitude' => $longitude, 'delivery_distance_m' => $result['distance_m'],
                'delivery_origin_latitude' => $origin['latitude'], 'delivery_origin_longitude' => $origin['longitude'], 'delivery_radius_m' => $origin['free_radius_m'],
                'delivery_coverage' => $result['coverage'], 'delivery_quote_status' => $result['coverage'] === 'free' ? 'not_required' : 'pending'];
        }

        try {
            return DB::transaction(function () use ($company, $branch, $data, $items, $hash, $delivery): OnlineOrderRequest {
                $variants = $this->catalog->validatedVariants($company, $branch, $items);
                $subtotal = BigDecimal::zero();
                $lines = [];
                foreach ($items as $item) {
                    $variant = $variants->get($item['variant_ulid']);
                    $line = BigDecimal::of($variant->price)->multipliedBy((int) $item['quantity'])->toScale(2, RoundingMode::HalfUp);
                    $subtotal = $subtotal->plus($line);
                    $lines[] = ['company_id' => $company->id, 'branch_id' => $branch->id,
                        'product_variant_id' => $variant->id, 'product_name' => $variant->product->name,
                        'variant_name' => $variant->name, 'quantity' => (int) $item['quantity'],
                        'was_promotion' => $variant->promotion !== null || $variant->product->type === ProductType::Combo,
                        'unit_price' => $variant->price, 'subtotal' => (string) $line];
                }
                $total = (string) $subtotal->toScale(2, RoundingMode::HalfUp);
                $request = OnlineOrderRequest::query()->create([
                    'company_id' => $company->id, 'branch_id' => $branch->id,
                    'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                    'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'],
                    'notes' => $data['notes'] ?? null, 'fulfillment' => $delivery ? 'delivery' : 'pickup',
                    'status' => 'pending_confirmation', 'subtotal' => $total, 'total' => $total,
                ] + $delivery);
                $request->items()->createMany($lines);

                return $request;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->find($company, $branch, $data['idempotency_key']);
            if ($existing) {
                return $this->assertSameRequest($existing, $hash);
            }
            throw $exception;
        }
    }

    private function find(Company $company, Branch $branch, string $key): ?OnlineOrderRequest
    {
        return OnlineOrderRequest::query()->forCompany($company)->where('branch_id', $branch->id)
            ->where('idempotency_key', $key)->first();
    }

    private function assertSameRequest(OnlineOrderRequest $request, string $hash): OnlineOrderRequest
    {
        if ($request->is_fence || ! hash_equals($request->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La clave ya corresponde a otra solicitud.']);
        }

        return $request;
    }

    /** Atomically fence a key or return the request that won the race. */
    public function resolve(Company $company, Branch $branch, string $key): OnlineOrderRequest
    {
        $existing = $this->find($company, $branch, $key);
        if ($existing) {
            return $existing;
        }

        try {
            return OnlineOrderRequest::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id,
                'idempotency_key' => $key, 'request_hash' => str_repeat('0', 64), 'is_fence' => true,
                'customer_name' => '', 'customer_phone' => '', 'fulfillment' => 'pickup',
                'status' => 'cancelled', 'subtotal' => '0.00', 'total' => '0.00',
            ]);
        } catch (QueryException $exception) {
            $existing = $this->find($company, $branch, $key);
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }
}
