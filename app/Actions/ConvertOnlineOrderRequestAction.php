<?php

namespace App\Actions;

use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderTotalsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

/** Runs inside the transaction that has locked and authorized the online request. */
class ConvertOnlineOrderRequestAction
{
    public function __construct(
        private readonly CreateTakeawayOrderAction $createOrder,
        private readonly AddOrderItemAction $addItem,
        private readonly OrderTotalsService $totals,
    ) {}

    public function execute(OnlineOrderRequest $request, User $user): Order
    {
        if (! config('storefront.online_pos_conversion_enabled')) {
            throw new DomainException('La conversión de pedidos online al POS está desactivada.');
        }

        $request->loadMissing(['company', 'items']);
        $branch = Branch::query()->where('company_id', $request->company_id)->findOrFail($request->branch_id);
        if ($request->fulfillment !== 'pickup' || $request->items->isEmpty()) {
            throw new DomainException('La solicitud no contiene un pedido válido de recojo.');
        }

        $order = $this->createOrder->execute($request->company, $branch, $user, [
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'notes' => $request->notes,
        ]);
        $sum = BigDecimal::zero();
        foreach ($request->items as $line) {
            if ($line->was_promotion === null) {
                throw new DomainException('La solicitud no conserva la clasificación histórica del producto. Revísala con el cliente.');
            }
            $variant = ProductVariant::query()->with(['product.category', 'promotion'])
                ->where('company_id', $request->company_id)->lockForUpdate()->find($line->product_variant_id);
            if (! $variant || ! $variant->is_active || ! $variant->product?->is_active || ! $variant->product->category?->is_active) {
                throw new DomainException('Un producto o variante ya no está disponible.');
            }
            // Existing online requests do not contain a promotion composition snapshot.
            if ($line->was_promotion || $variant->promotion || $variant->product->type === ProductType::Combo) {
                throw new DomainException('La promoción no contiene una configuración histórica verificable. Contacta al cliente antes de continuar.');
            }
            $price = BigDecimal::of($line->unit_price);
            $subtotal = $price->multipliedBy($line->quantity)->toScale(2, RoundingMode::HalfUp);
            if ($line->quantity < 1 || $price->isNegative() || ! $subtotal->isEqualTo($line->subtotal)) {
                throw new DomainException('Los importes históricos de la solicitud son inconsistentes.');
            }

            $item = $this->addItem->execute($order, $variant, $line->quantity, $user);
            $snapshot = $item->configuration_snapshot ?? [];
            $snapshot['online_request'] = ['request_id' => $request->id, 'item_id' => $line->id,
                'product_name' => $line->product_name, 'variant_name' => $line->variant_name,
                'unit_price' => $line->unit_price, 'converted_by' => $user->id];
            $sections = $item->sections()->get();
            if ($sections->isNotEmpty()) {
                if ($sections->count() !== 1 || ! empty($snapshot['modifiers'])) {
                    throw new DomainException('La pizza necesita una configuración histórica que no está disponible.');
                }
                $section = $sections->first();
                $section->update(['unit_price_snapshot' => $line->unit_price, 'product_name_snapshot' => $line->product_name, 'variant_name_snapshot' => $line->variant_name]);
                $snapshot['sections'][0]['unit_price'] = $line->unit_price;
                $snapshot['sections'][0]['product'] = $line->product_name;
                $snapshot['sections'][0]['variant'] = $line->variant_name;
            }
            $item->update(['unit_price' => $line->unit_price, 'line_total' => (string) $subtotal, 'configuration_snapshot' => $snapshot]);
            $sum = $sum->plus($subtotal);
        }
        if (! $sum->isEqualTo($request->subtotal) || ! $sum->isEqualTo($request->total)) {
            throw new DomainException('El total histórico de la solicitud es inconsistente.');
        }
        $order = $this->totals->recalculate($order->fresh());
        if (! BigDecimal::of($order->total)->isEqualTo($request->total)
            || ! BigDecimal::of($order->subtotal)->isEqualTo($request->subtotal)) {
            throw new DomainException('El cálculo financiero del POS no coincide con la solicitud histórica.');
        }

        return $order;
    }
}
