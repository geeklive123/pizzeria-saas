<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\OrderCancellationAudit;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InventoryAvailabilityService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class RestoreCancellationInventoryAction
{
    public function __construct(
        private readonly ApplyInventoryMovementAction $applyMovement,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    /** @return list<InventoryMovement> */
    public function execute(OrderCancellationAudit $audit, OrderItem $item, User $user): array
    {
        if ((int) $audit->order_item_id !== (int) $item->getKey()) {
            throw new DomainException('La auditoría no corresponde al producto seleccionado.');
        }

        $snapshot = $audit->snapshot;
        $reservationSnapshots = collect($snapshot['reservations'] ?? []);
        $movementSnapshots = collect($snapshot['inventory_reversals'] ?? []);
        $requirements = [];

        $originalMovements = InventoryMovement::query()
            ->whereIn('id', $movementSnapshots->pluck('original_movement_id'))
            ->orderBy('inventory_item_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $reversalMovements = InventoryMovement::query()
            ->whereIn('id', $movementSnapshots->pluck('reversal_movement_id'))
            ->orderBy('inventory_item_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($movementSnapshots as $entry) {
            $original = $originalMovements->get($entry['original_movement_id']);
            $reversal = $reversalMovements->get($entry['reversal_movement_id']);
            if (! $original || ! $reversal
                || $original->type !== InventoryMovementType::OrderConsumption
                || (int) $original->reference_id !== (int) $item->getKey()
                || (int) $reversal->reversal_of_id !== (int) $original->getKey()) {
                throw new DomainException('La evidencia de reversión de inventario es inconsistente.');
            }
            $requirements[$original->inventory_item_id] = BigDecimal::of($requirements[$original->inventory_item_id] ?? '0')
                ->plus($original->quantity);
        }

        foreach ($reservationSnapshots as $entry) {
            $requirements[$entry['inventory_item_id']] = BigDecimal::of($requirements[$entry['inventory_item_id']] ?? '0')
                ->plus($entry['quantity']);
        }

        foreach (collect($requirements)->sortKeys() as $inventoryItemId => $required) {
            $stock = InventoryStock::query()
                ->where('company_id', $item->company_id)
                ->where('branch_id', $item->branch_id)
                ->where('inventory_item_id', $inventoryItemId)
                ->lockForUpdate()->firstOrFail();
            $reserved = InventoryReservation::query()
                ->where('company_id', $item->company_id)
                ->where('branch_id', $item->branch_id)
                ->where('inventory_item_id', $inventoryItemId)
                ->where('status', InventoryReservationStatus::Reserved->value)
                ->orderBy('id')->lockForUpdate()->get()
                ->reduce(fn (BigDecimal $total, InventoryReservation $reservation): BigDecimal => $total->plus($reservation->quantity), BigDecimal::zero());
            $available = $this->availability->forStock($stock, reservedQuantity: (string) $reserved);
            if (BigDecimal::of($available->availableQuantity)->isLessThan($required)) {
                throw new DomainException('Stock insuficiente para restaurar todos los productos de la anulación.');
            }
        }

        foreach ($reservationSnapshots as $entry) {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($entry['id']);
            if ((int) $reservation->order_item_id !== (int) $item->getKey()
                || $reservation->status !== InventoryReservationStatus::Released) {
                throw new DomainException('La reserva que debe restaurarse ya no está en el estado esperado.');
            }
            $reservation->forceFill([
                'quantity' => (string) BigDecimal::of($entry['quantity'])->toScale(3, RoundingMode::HalfUp),
                'status' => InventoryReservationStatus::Reserved,
                'reserved_at' => now(),
                'released_at' => null,
                'consumed_at' => null,
            ])->save();
        }

        $created = [];
        foreach ($movementSnapshots->sortBy(fn (array $entry): string => str_pad((string) $originalMovements[$entry['original_movement_id']]->inventory_item_id, 20, '0', STR_PAD_LEFT)) as $entry) {
            $original = $originalMovements[$entry['original_movement_id']];
            $created[] = $this->applyMovement->execute(
                $item->company,
                $item->order->branch,
                $original->inventoryItem,
                InventoryMovementType::OrderConsumption,
                $original->quantity,
                $original->unit_cost,
                $user,
                reason: 'Restauración de anulación: '.$audit->ulid,
                referenceType: OrderItem::class,
                referenceId: $item->getKey(),
                metadata: [
                    'cancellation_restore_audit_id' => $audit->getKey(),
                    'restores_original_movement_id' => $original->getKey(),
                    'compensates_reversal_movement_id' => $entry['reversal_movement_id'],
                ],
            );
        }

        return $created;
    }
}
