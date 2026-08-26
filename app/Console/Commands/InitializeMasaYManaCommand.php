<?php

namespace App\Console\Commands;

use App\Actions\ImportMasaYManaMenuAction;
use App\Actions\InitializeMasaYManaBusinessAction;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

class InitializeMasaYManaCommand extends Command
{
    protected $signature = 'app:initialize-masa-y-mana
        {--company= : Nombre, ID o ULID de la empresa activa}
        {--branch= : Nombre, ID o ULID de la sucursal activa}
        {--actor-email= : Owner o admin activo usado para autorizar la inicialización}';

    protected $description = 'Inicializa datos maestros y el catálogo validado de Masa & Maña';

    public function handle(
        InitializeMasaYManaBusinessAction $initialize,
        ImportMasaYManaMenuAction $importMenu,
    ): int {
        try {
            $company = $this->resolveCompany();
            $branch = $this->resolveBranch($company);
            $actor = $this->resolveActor($company);
            $preview = $importMenu->preview();

            $this->components->info('Resumen previo: no se ha modificado ningún dato.');
            $this->table(['Dato', 'Valor'], [
                ['Entorno', app()->environment()],
                ['Empresa', $company->name],
                ['Sucursal', $branch->name],
                ['Actor autorizado', $actor->email],
                ['Archivo', ImportMasaYManaMenuAction::SOURCE_FILE],
                ['Unidades estándar', 5],
                ['Categorías de productos', count(ImportMasaYManaMenuAction::CATEGORY_NAMES)],
                ['Categorías de egreso', count(InitializeMasaYManaBusinessAction::EXPENSE_CATEGORY_NAMES)],
                ['Productos importables', $preview['products']],
                ['Variantes importables', $preview['variants']],
                ['Filas omitidas sin precio', $preview['omitted']],
            ]);

            if (! $this->confirm('¿Confirmas la inicialización idempotente de esta empresa y sucursal?', false)) {
                $this->components->warn('Inicialización cancelada. No se modificó ningún dato.');

                return self::SUCCESS;
            }

            $result = $initialize->execute($company, $branch, $actor);

            $this->components->info('Inicialización completada correctamente.');
            $this->table(['Resultado', 'Total', 'Creados', 'Reutilizados'], [
                ['Unidades creadas/reutilizadas', 5, $result['units_created'], $result['units_reused']],
                ['Categorías creadas/reutilizadas', 5, $result['categories_created'], $result['categories_reused']],
                ['Categorías de egreso creadas/reutilizadas', 11, $result['expense_categories_created'], $result['expense_categories_reused']],
                ['Productos creados/reutilizados', 32, $result['products_created'], $result['products_reused']],
                ['Variantes creadas/reutilizadas', 66, $result['variants_created'], $result['variants_reused']],
                ['Omitidos', $result['omitted'], '—', '—'],
                ['Errores', $result['errors'], '—', '—'],
            ]);

            return self::SUCCESS;
        } catch (DomainException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());
            $this->table(['Resultado', 'Total'], [['Errores', 1]]);

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('La inicialización falló y la transacción fue revertida.');
            $this->table(['Resultado', 'Total'], [['Errores', 1]]);

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
                ?? throw new DomainException('No se encontró una sucursal activa de la empresa con el selector indicado.');
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
        $query = Membership::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->with(['user', 'permissionOverrides']);

        if ($email !== '') {
            $query->whereHas('user', fn (Builder $users): Builder => $users->where('email', $email));
        }

        $membership = $query->get()->first(fn (Membership $membership): bool => $membership->allows(Permission::ManageCatalog)
            && $membership->allows(Permission::ManageExpenseCategories));

        if (! $membership) {
            throw new DomainException('No existe un owner/admin activo con los permisos necesarios en la empresa.');
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
