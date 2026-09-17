<?php

namespace App\Console\Commands;

use App\Actions\RestoreLegacyCancelledPaidOrderAction;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Services\LegacyRestoreEnvironmentGuard;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class RestoreLegacyCancelledPaidOrderCommand extends Command
{
    protected $signature = 'orders:restore-legacy-cancelled-paid
        {operational_number : Número operacional de la venta}
        {--company= : ID de empresa}
        {--branch= : ID de sucursal}
        {--allow-production : Habilita explicitamente la validacion en APP_ENV=production}
        {--actor-email= : Email del owner/admin que autoriza la recuperación}
        {--execute : Ejecuta la recuperación; sin esta opción solo realiza dry-run}';

    protected $description = 'Valida o recupera administrativamente una venta pagada legacy anulada';

    public function handle(
        RestoreLegacyCancelledPaidOrderAction $action,
        LegacyRestoreEnvironmentGuard $environmentGuard,
    ): int {
        try {
            $environment = $environmentGuard->assertAllowed((bool) $this->option('allow-production'));
            $operationalNumber = $this->requiredOperationalNumber();
            $companyId = $this->requiredIntegerOption('company');
            $branchId = $this->requiredIntegerOption('branch');
            $order = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->where('operational_number', $operationalNumber)->firstOrFail();
            $actor = $this->resolveActor($companyId);
            $expectations = $this->expectations($order, $environment === 'production');
            $report = $action->dryRun($order, $actor, $expectations);

            $this->components->info('Dry-run válido. No se modificó ningún dato.');
            $this->renderSummary($report, $actor, $environment, (bool) $this->option('execute'));
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (! $this->option('execute')) {
                return self::SUCCESS;
            }

            $audit = $action->execute($order, $actor, $expectations);
            $this->components->info('Recuperacion administrativa legacy completada en '.$environment.'.');
            $this->table(['Resultado', 'Valor'], [
                ['Order', $order->getKey()],
                ['Operational number', $order->operational_number],
                ['Audit', $audit->getKey()],
                ['Audit ULID', $audit->ulid],
                ['Productos restaurados', $audit->children->count()],
                ['Motivo', RestoreLegacyCancelledPaidOrderAction::RESTORATION_REASON],
            ]);

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('La recuperación falló y cualquier cambio fue revertido.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredIntegerOption(string $name): int
    {
        $value = trim((string) $this->option($name));
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            throw new DomainException("Debes indicar --{$name} con un ID válido.");
        }

        return (int) $value;
    }

    private function requiredOperationalNumber(): int
    {
        $value = trim((string) $this->argument('operational_number'));
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            throw new DomainException('Debes indicar un operational_number valido.');
        }

        return (int) $value;
    }

    private function resolveActor(int $companyId): User
    {
        $email = strtolower(trim((string) $this->option('actor-email')));
        if ($email === '') {
            throw new DomainException('Debes indicar --actor-email para auditar quién autoriza la recuperación.');
        }

        $membership = Membership::query()->where('company_id', $companyId)->where('is_active', true)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->whereHas('user', fn (Builder $query): Builder => $query->where('email', $email))
            ->with(['user', 'permissionOverrides'])->first();

        if (! $membership || ! $membership->allows(Permission::RestoreCancelledOrders)) {
            throw new DomainException('El actor no es owner/admin activo con permiso para restaurar anulaciones.');
        }

        return $membership->user;
    }

    /** @return array<string, mixed> */
    private function expectations(Order $order, bool $production): array
    {
        if ((int) $order->company_id !== 1 || (int) $order->branch_id !== 1
            || (int) $order->operational_number !== 156) {
            if ($production) {
                throw new DomainException('En production este comando esta limitado a company=1, branch=1 y operational_number=156.');
            }

            return [];
        }

        $expectations = [
            'order.id' => 210,
            'order.operational_number' => 156,
            'order.status' => 'cancelled',
            'order.total' => '99.00',
            'order.closed_at' => '2026-09-16 21:01:49',
            'order.cancelled_at' => '2026-09-16 22:58:02',
            'order.cancelled_by' => 5,
            'order.cancellation_reason' => 'Repetido',
            'payments.0.original_payment_id' => 152,
            'payments.0.reversal_payment_id' => 157,
            'payments.0.cash_session_id' => 29,
            'payments.0.kitchen_dispatch_id' => 179,
            'payments.0.method' => 'qr',
            'payments.0.amount' => '99.00',
            'cash_session_transfer_ids' => [3],
            'cash_sessions.0.id' => 29,
            'cash_sessions.0.status' => 'open',
            'dispatches.0.id' => 179,
            'dispatches.0.current_status' => 'cancelled',
            'dispatches.0.previous_dispatch_status' => 'settled',
            'dispatches.0.total' => '99.00',
            'items.0.id' => 320,
            'items.0.current_status' => 'cancelled',
            'items.0.previous_item_status' => 'sent',
            'items.0.kitchen_dispatch_id' => 179,
            'items.0.reservation_ids' => [1332, 1333, 1334, 1335, 1336, 1337],
        ];
        $movementIds = [1304, 1305, 1306, 1307, 1308, 1309];
        $reversalIds = [1321, 1322, 1323, 1324, 1325, 1326];
        $inventoryItemIds = [24, 46, 47, 48, 49, 65];
        $quantities = ['100.000', '180.000', '5.000', '18.000', '30.000', '310.000'];
        $batchIds = [26, 41, 42, 43, 85, 86];

        foreach ($movementIds as $index => $movementId) {
            $prefix = 'items.0.inventory_pairs.'.$index;
            $expectations[$prefix.'.original_movement_id'] = $movementId;
            $expectations[$prefix.'.reversal_movement_id'] = $reversalIds[$index];
            $expectations[$prefix.'.inventory_item_id'] = $inventoryItemIds[$index];
            $expectations[$prefix.'.quantity'] = $quantities[$index];
            $expectations[$prefix.'.batch_allocations.0.batch_id'] = $batchIds[$index];
        }

        return $expectations;
    }

    /** @param array<string, mixed> $report */
    private function renderSummary(array $report, User $actor, string $environment, bool $execute): void
    {
        $payment = $report['payments'][0];
        $dispatch = $report['dispatches'][0];
        $item = $report['items'][0];
        $movementPairs = collect($item['inventory_pairs'])->map(
            fn (array $pair): string => $pair['original_movement_id'].' -> '.$pair['reversal_movement_id']
                .' ('.$pair['quantity'].')',
        )->implode(', ');

        $this->newLine();
        $this->components->warn($execute
            ? 'OPERACION CON ESCRITURAS: se ejecutara la recuperacion administrativa legacy.'
            : 'DRY-RUN: no se realizara ninguna escritura.');
        $this->table(['Validacion', 'Valor'], [
            ['Entorno', $environment],
            ['Operacion', $execute ? 'RESTAURAR VENTA LEGACY' : 'SOLO VALIDAR'],
            ['Order', $report['order']['id'].' / #'.$report['order']['operational_number']],
            ['Total', $report['order']['total']],
            ['Pago original / reversion', $payment['original_payment_id'].' / '.$payment['reversal_payment_id']],
            ['Tanda', $dispatch['id'].' ('.$dispatch['previous_dispatch_status'].')'],
            ['Producto', $item['id'].' ('.$item['previous_item_status'].')'],
            ['Inventario', $movementPairs],
            ['Actor', $actor->name.' <'.$actor->email.'> [ID '.$actor->getKey().']'],
        ]);
    }
}
