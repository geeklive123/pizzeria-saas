<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Preparation;
use App\Models\PreparationComponent;
use App\Models\User;
use App\Services\CompanyAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavePreparationAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    /** @param list<array{inventory_item_id:int,quantity:int|string}> $components */
    public function execute(
        Company $company,
        User $user,
        string $name,
        InventoryItem $output,
        int|string $theoreticalYield,
        bool $isActive,
        array $components,
        ?Preparation $preparation = null,
    ): Preparation {
        $this->access->ensure($user, $company, Permission::ManageInventory);

        if ((int) $output->company_id !== (int) $company->getKey() || ! $output->ingredient_id || ! $output->is_active) {
            throw ValidationException::withMessages(['output_inventory_item_id' => 'La salida no es válida para esta empresa.']);
        }
        if ($preparation && (int) $preparation->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages(['preparation' => 'La preparación no pertenece a la empresa activa.']);
        }

        try {
            $yield = BigDecimal::of($theoreticalYield)->toScale(3, RoundingMode::HalfUp);
        } catch (NumberFormatException) {
            $yield = BigDecimal::zero();
        }
        if ($yield->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['theoretical_yield' => 'El rendimiento debe ser mayor que cero.']);
        }

        $validated = $this->components($company, $output, $components);

        return DB::transaction(function () use ($company, $name, $output, $yield, $isActive, $validated, $preparation): Preparation {
            if ($preparation) {
                $preparation = Preparation::query()->forCompany($company)->whereKey($preparation->getKey())
                    ->lockForUpdate()->firstOrFail();
                $preparation->update([
                    'name' => $name,
                    'output_inventory_item_id' => $output->getKey(),
                    'unit_id' => $output->unit_id,
                    'theoretical_yield' => (string) $yield,
                    'is_active' => $isActive,
                ]);
            } else {
                $preparation = Preparation::query()->create([
                    'company_id' => $company->getKey(),
                    'name' => $name,
                    'output_inventory_item_id' => $output->getKey(),
                    'unit_id' => $output->unit_id,
                    'theoretical_yield' => (string) $yield,
                    'is_active' => $isActive,
                ]);
            }

            $ids = array_keys($validated);
            $preparation->components()->whereNotIn('inventory_item_id', $ids)->delete();
            $now = now();
            $rows = array_map(fn (int $id, string $quantity): array => [
                'company_id' => $company->getKey(),
                'preparation_id' => $preparation->getKey(),
                'inventory_item_id' => $id,
                'quantity' => $quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ], $ids, array_values($validated));
            PreparationComponent::query()->upsert($rows, ['preparation_id', 'inventory_item_id'], ['quantity', 'updated_at']);

            return $preparation->refresh()->load(['outputInventoryItem.unit', 'components.inventoryItem.unit']);
        });
    }

    /** @param list<array{inventory_item_id:int,quantity:int|string}> $components
     * @return array<int,string>
     */
    private function components(Company $company, InventoryItem $output, array $components): array
    {
        if ($components === []) {
            throw ValidationException::withMessages(['components' => 'Agrega al menos un componente.']);
        }

        $validated = [];
        foreach ($components as $index => $component) {
            $id = (int) ($component['inventory_item_id'] ?? 0);
            if ($id < 1 || $id === (int) $output->getKey()) {
                throw ValidationException::withMessages(["components.$index.inventory_item_id" => 'Selecciona una materia prima distinta de la salida.']);
            }
            if (isset($validated[$id])) {
                throw ValidationException::withMessages(["components.$index.inventory_item_id" => 'Ese componente ya está incluido.']);
            }
            try {
                $quantity = BigDecimal::of($component['quantity'] ?? '')->toScale(3, RoundingMode::HalfUp);
            } catch (NumberFormatException) {
                $quantity = BigDecimal::zero();
            }
            if ($quantity->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(["components.$index.quantity" => 'La cantidad debe ser mayor que cero.']);
            }
            $validated[$id] = (string) $quantity;
        }

        $validCount = InventoryItem::query()->forCompany($company)->whereKey(array_keys($validated))
            ->whereNotNull('ingredient_id')->where('is_active', true)->count();
        if ($validCount !== count($validated)) {
            throw ValidationException::withMessages(['components' => 'Todos los componentes deben ser ingredientes activos de la empresa.']);
        }

        return $validated;
    }
}
