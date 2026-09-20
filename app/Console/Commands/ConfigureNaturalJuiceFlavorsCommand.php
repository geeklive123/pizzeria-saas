<?php

namespace App\Console\Commands;

use App\Actions\ConfigureNaturalJuiceFlavorsAction;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ConfigureNaturalJuiceFlavorsCommand extends Command
{
    protected $signature = 'app:configure-natural-juice-flavors
        {--company= : Nombre, ID o ULID de la empresa activa}
        {--branch= : Nombre, ID o ULID de la sucursal activa}
        {--actor-email= : Owner o admin con permisos de catálogo e inventario}
        {--apply : Aplica la configuración y el stock inicial}';

    protected $description = 'Configura sabores y stock directo independiente para Jugos Naturales';

    public function handle(ConfigureNaturalJuiceFlavorsAction $configure): int
    {
        try {
            $company = $this->resolveCompany();
            $branch = $this->resolveBranch($company);
            $preview = $configure->preview($company, $branch);
            $this->components->info('Resumen previo: no se ha modificado ningún dato.');
            $this->table(['Dato', 'Valor'], [
                ['Empresa', $company->name],
                ['Sucursal', $branch->name],
                ['Producto', $preview['product_name']],
                ['Product ID', $preview['product_id']],
                ['Variante histórica', $preview['historical_variant_name'].' (ID '.$preview['historical_variant_id'].')'],
                ['OrderItems históricos', $preview['historical_order_items']],
                ['Precio de los sabores', $preview['price']],
            ]);
            $this->table(
                ['Sabor', 'Inicial', 'Variante', 'InventoryItem', 'Actual', 'Carga'],
                collect($preview['flavors'])->map(fn (array $flavor): array => [
                    $flavor['name'], $flavor['initial_stock'], $flavor['variant_id'] ?? 'crear',
                    $flavor['inventory_item_id'] ?? 'crear', $flavor['current_stock'],
                    $flavor['initial_stock_loaded'] ? 'registrada' : 'pendiente',
                ])->all(),
            );
            if (! $this->option('apply')) {
                $this->components->warn('Simulación finalizada. Usa --apply para solicitar la aplicación.');

                return self::SUCCESS;
            }

            $actor = $this->resolveActor($company);
            if (! $this->confirm('Se desactivará Única y se cargará el stock inicial por sabor. ¿Continuar?', false)) {
                $this->components->warn('Operación cancelada. No se modificó ningún dato.');

                return self::SUCCESS;
            }
            $result = $configure->execute($company, $branch, $actor);
            $this->components->info('Jugos Naturales configurados correctamente.');
            $this->table(['Resultado', 'Cantidad'], [
                ['Variantes creadas', $result['variants_created']],
                ['Variantes reutilizadas', $result['variants_reused']],
                ['InventoryItems creados', $result['inventory_items_created']],
                ['InventoryItems reutilizados', $result['inventory_items_reused']],
                ['Stocks iniciales cargados', $result['stock_loaded']],
                ['Stocks iniciales ya registrados', $result['stock_skipped']],
            ]);

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('La configuración falló y la transacción fue revertida.');

            return self::FAILURE;
        }
    }

    private function resolveCompany(): Company
    {
        $selector = trim((string) $this->option('company'));
        $query = Company::query()->where('is_active', true);
        if ($selector !== '') {
            $this->applySelector($query, $selector);

            return $query->first()
                ?? throw new DomainException('No se encontró una empresa activa con el selector indicado.');
        }
        $companies = $query->limit(2)->get();
        if ($companies->count() !== 1) {
            throw new DomainException('Indica --company porque no existe una única empresa activa.');
        }

        return $companies->first();
    }

    private function resolveBranch(Company $company): Branch
    {
        $selector = trim((string) $this->option('branch'));
        $query = Branch::query()->forCompany($company)->where('is_active', true);
        if ($selector !== '') {
            $this->applySelector($query, $selector);

            return $query->first()
                ?? throw new DomainException('No se encontró una sucursal activa con el selector indicado.');
        }
        $branches = $query->limit(2)->get();
        if ($branches->count() !== 1) {
            throw new DomainException('Indica --branch porque la empresa no tiene una única sucursal activa.');
        }

        return $branches->first();
    }

    private function resolveActor(Company $company): User
    {
        $email = strtolower(trim((string) $this->option('actor-email')));
        if ($email === '') {
            throw new DomainException('Indica --actor-email para aplicar la configuración.');
        }
        $query = Membership::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->with(['user', 'permissionOverrides']);
        $query->whereHas('user', fn (Builder $users): Builder => $users->where('email', $email));
        $membership = $query->get()->first(
            fn (Membership $membership): bool => $membership->allows(Permission::ManageCatalog)
                && $membership->allows(Permission::ManageInventory),
        );
        if (! $membership) {
            throw new DomainException('No existe un owner/admin activo con permisos de catálogo e inventario.');
        }

        return $membership->user;
    }

    /** @param Builder<Company|Branch> $query */
    private function applySelector(Builder $query, string $selector): void
    {
        $query->where(function (Builder $match) use ($selector): void {
            $match->where('ulid', $selector)->orWhere('name', $selector);
            if (ctype_digit($selector)) {
                $match->orWhere($match->getModel()->getKeyName(), (int) $selector);
            }
        });
    }
}
