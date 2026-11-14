<?php

namespace App\Actions;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ProductVariant;
use App\Services\PromotionAvailabilityService;
use App\Services\SellableAvailabilityService;
use App\Services\StorefrontVisibilityService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BuildStorefrontWhatsAppOrderAction
{
    public function __construct(
        private readonly SellableAvailabilityService $availability,
        private readonly PromotionAvailabilityService $promotionAvailability,
        private readonly StorefrontVisibilityService $visibility,
    ) {}

    /**
     * @param  array{
     *     customer_name:string,customer_phone:string,
     *     fulfillment:string,address?:?string,reference?:?string,notes?:?string,
     *     items:list<array{variant_ulid:string,quantity:int}>
     * }  $data
     * @return array{url:?string,message:string,subtotal:string,item_count:int,demo:bool}
     */
    public function execute(Company $company, Branch $branch, array $data): array
    {
        $requested = collect($data['items']);
        $variants = $this->validatedVariants($company, $branch, $requested);

        [$detailLines, $subtotal, $itemCount] = $this->detail($requested, $variants);
        $destination = config('storefront.checkout_whatsapp');
        $isConfiguredStore = (int) data_get($destination, 'company_id') === (int) $company->id
            && (int) data_get($destination, 'branch_id') === (int) $branch->id;
        $message = $this->message($company, $isConfiguredStore, $data, $detailLines, $subtotal);

        if ((bool) config('storefront.demo_mode', true)) {
            return [
                'url' => null,
                'message' => $message,
                'subtotal' => $subtotal,
                'item_count' => $itemCount,
                'demo' => true,
            ];
        }

        $phone = $isConfiguredStore
            ? $this->whatsAppPhone(data_get($destination, 'number'))
            : $this->whatsAppPhone($branch->phone ?: $company->phone);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'storefront' => 'El restaurante todavía no tiene un número oficial de WhatsApp configurado.',
            ]);
        }

        return [
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message),
            'message' => $message,
            'subtotal' => $subtotal,
            'item_count' => $itemCount,
            'demo' => false,
        ];
    }

    /** @param Collection<int, array{variant_ulid:string,quantity:int}> $requested */
    public function validatedVariants(Company $company, Branch $branch, Collection $requested): Collection
    {
        $variants = ProductVariant::query()
            ->forCompany($company)
            ->whereIn('ulid', $requested->pluck('variant_ulid'))
            ->where('is_active', true)
            ->whereHas('product', function (Builder $product) use ($company): void {
                $product->where('company_id', $company->getKey())
                    ->where('is_active', true)
                    ->whereHas('category', function (Builder $category) use ($company): void {
                        $category->where('company_id', $company->getKey())
                            ->where('is_active', true);
                        $this->visibility->applyCategoryVisibility($category);
                    });
                $this->visibility->applyProductVisibility($product);
            })
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('promotion')
                ->orWhereHas('promotion', fn (Builder $promotion) => $promotion->currentlyActive()))
            ->with(['product', 'promotion'])
            ->get()
            ->keyBy('ulid');

        if ($variants->count() !== $requested->count()) {
            throw ValidationException::withMessages([
                'items' => 'Uno o más productos ya no están disponibles en esta tienda.',
            ]);
        }

        $this->assertAvailability($branch, $requested, $variants);

        return $variants;
    }

    /**
     * @param  Collection<int, array{variant_ulid:string,quantity:int}>  $requested
     * @param  Collection<string, ProductVariant>  $variants
     */
    private function assertAvailability(Branch $branch, Collection $requested, Collection $variants): void
    {
        foreach ($requested as $item) {
            $variant = $variants->get($item['variant_ulid']);
            $result = $variant->promotion
                ? $this->promotionAvailability->calculate($variant->promotion, $branch, OrderType::Takeaway)
                : $this->availability->calculate($variant, $branch);

            if (in_array($result->mode, ['recipe_pending', 'untracked'], true)) {
                continue;
            }

            if (BigDecimal::of($result->availableQuantity)->isLessThan((string) $item['quantity'])) {
                throw ValidationException::withMessages([
                    'items' => 'La disponibilidad de uno o más productos cambió. Actualiza tu pedido e inténtalo nuevamente.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, array{variant_ulid:string,quantity:int}>  $requested
     * @param  Collection<string, ProductVariant>  $variants
     * @return array{list<string>, string, int}
     */
    private function detail(Collection $requested, Collection $variants): array
    {
        $subtotal = BigDecimal::zero();
        $itemCount = 0;
        $lines = [];

        foreach ($requested as $item) {
            $variant = $variants->get($item['variant_ulid']);
            $quantity = (int) $item['quantity'];
            $lineTotal = BigDecimal::of($variant->price)
                ->multipliedBy($quantity)
                ->toScale(2, RoundingMode::HalfUp);
            $subtotal = $subtotal->plus($lineTotal);
            $itemCount += $quantity;
            $lines[] = sprintf(
                '%d × %s (%s) — Bs %s',
                $quantity,
                $variant->product->name,
                $variant->name,
                $this->displayMoney((string) $lineTotal),
            );
        }

        return [$lines, (string) $subtotal->toScale(2, RoundingMode::HalfUp), $itemCount];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $detailLines
     */
    private function message(Company $company, bool $isConfiguredStore, array $data, array $detailLines, string $subtotal): string
    {
        $isDelivery = $data['fulfillment'] === 'delivery';
        $storeName = $isConfiguredStore ? 'Masa y Maña' : $company->name;
        $lines = [
            '*'.mb_strtoupper($storeName).' — NUEVO PEDIDO ONLINE*',
            '',
            '*Cliente:* '.$data['customer_name'],
            '*Celular:* '.$data['customer_phone'],
            '*Modalidad:* '.($isDelivery ? 'Delivery' : 'Recojo en local'),
        ];

        if ($isDelivery) {
            $lines[] = '*Dirección:* '.$data['address'];
            if (filled($data['reference'] ?? null)) {
                $lines[] = '*Referencia:* '.$data['reference'];
            }
        }

        array_push($lines, '', '*DETALLE DEL PEDIDO*', ...$detailLines);
        $lines[] = '';
        $lines[] = '*Subtotal:* Bs '.$this->displayMoney($subtotal);
        if ($isDelivery) {
            $lines[] = '';
            $lines[] = '*Delivery:* Por confirmar';
        }
        if (filled($data['notes'] ?? null)) {
            $lines[] = '';
            $lines[] = '*Observaciones:* '.$data['notes'];
        }
        $lines[] = '';
        $lines[] = 'Pedido enviado desde la tienda online de '.$storeName.'.';

        return implode("\n", $lines);
    }

    private function whatsAppPhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return is_string($digits) && strlen($digits) >= 8 ? $digits : null;
    }

    private function displayMoney(string $amount): string
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '00');

        return $decimal === '00' ? $whole : $whole.','.rtrim($decimal, '0');
    }
}
