<?php

namespace App\Services;

use App\Enums\InventoryReservationStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CashSession;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Verifies only reservations belonging to the authenticated internal operation. */
class OnlineOrderReservationVerifier
{
    public function __construct(
        private readonly OrderReservationRequirementsService $requirements,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function verify(OnlineOrderRequest $request, CashSession $session): void
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('La validación de reservas requiere una transacción.');
        }
        $operation = DB::table('online_qr_operations')->where('online_order_request_id', $request->id)
            ->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
            ->where('cash_session_id', $session->id)->where('original_cashier_id', $session->opened_by)
            ->lockForUpdate()->first();
        if (! $operation || (int) $operation->pos_order_id !== (int) $request->pos_order_id
            || ! DB::table('online_system_identities')->where('company_id', $request->company_id)
                ->where('branch_id', $request->branch_id)->where('user_id', $operation->system_user_id)->exists()) {
            $this->invalid();
        }
        $order = Order::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
            ->whereKey($request->pos_order_id)->lockForUpdate()->first();
        if (! $order || $request->fulfillment !== 'delivery' || $order->type !== OrderType::Takeaway
            || ! in_array($order->status, [OrderStatus::Open, OrderStatus::ReadyForPayment], true) || (int) $order->created_by !== (int) $operation->system_user_id
            || ! BigDecimal::of($order->total)->isEqualTo($request->total) || $order->payments()->exists()) {
            $this->invalid();
        }
        $items = $order->items()->with('productVariant')->orderBy('id')->lockForUpdate()->get();
        $lines = $request->items()->orderBy('id')->get()->keyBy('product_variant_id');
        if ($items->count() !== $lines->count() || $lines->count() !== $request->items()->count()) {
            $this->invalid();
        }
        $expected = [];
        foreach ($items as $item) {
            $line = $lines->get($item->product_variant_id);
            if (! $line || ! in_array($item->status, [OrderItemStatus::Draft, OrderItemStatus::PendingPayment], true)
                || (int) $item->created_by !== (int) $operation->system_user_id
                || ! BigDecimal::of($item->quantity)->isEqualTo($line->quantity)
                || ! BigDecimal::of($item->unit_price)->isEqualTo($line->unit_price)
                || ! BigDecimal::of($item->line_total)->isEqualTo($line->subtotal)) {
                $this->invalid();
            }
            $lines->forget($item->product_variant_id);
            try {
                foreach ($this->requirements->forOrderItem($item, $item->quantity) as $requirement) {
                    $key = $item->id.':'.$requirement['inventory_item']->id;
                    $expected[$key] = ($expected[$key] ?? BigDecimal::zero())->plus($requirement['quantity']);
                }
            } catch (DomainException) {
                $this->invalid();
            }
        }
        if ($lines->isNotEmpty()) {
            $this->invalid();
        }
        $inventoryIds = collect(array_keys($expected))->map(fn (string $key) => (int) explode(':', $key)[1])->unique()->sort()->values();
        $stocks = InventoryStock::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
            ->whereIn('inventory_item_id', $inventoryIds)->orderBy('inventory_item_id')->lockForUpdate()->get()->keyBy('inventory_item_id');
        $reservations = InventoryReservation::query()->where('company_id', $request->company_id)->where('branch_id', $request->branch_id)
            ->whereIn('inventory_item_id', $inventoryIds)->where('status', InventoryReservationStatus::Reserved->value)
            ->orderBy('id')->lockForUpdate()->get();
        $own = InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($own->count() !== count($expected)) {
            $this->invalid();
        }
        foreach ($own as $reservation) {
            $key = $reservation->order_item_id.':'.$reservation->inventory_item_id;
            if ((int) $reservation->company_id !== (int) $request->company_id || (int) $reservation->branch_id !== (int) $request->branch_id
                || $reservation->status !== InventoryReservationStatus::Reserved || ! isset($expected[$key])
                || ! $expected[$key]->isEqualTo($reservation->quantity)) {
                $this->invalid();
            }
            unset($expected[$key]);
        }
        if ($expected !== []) {
            $this->invalid();
        }
        try {
            foreach ($inventoryIds as $id) {
                $reserved = $this->availability->reservedQuantity($reservations->where('inventory_item_id', $id));
                $this->availability->forStock($stocks->get($id), reservedQuantity: $reserved);
            }
        } catch (DomainException) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['items' => 'No se pudo verificar la reserva de este pedido.']);
    }
}
