<?php

namespace App\Console\Commands;

use App\Actions\ProvisionProductionOwnerAction;
use App\Enums\Permission;
use App\Models\Branch;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProvisionProductionOwnerCommand extends Command
{
    protected $signature = 'app:provision-owner
        {--allow-existing-owner : Permite agregar explícitamente otro owner a una empresa que ya tiene uno activo}';

    protected $description = 'Aprovisiona de forma segura el primer owner, empresa y sucursal de producción';

    public function handle(ProvisionProductionOwnerAction $action): int
    {
        try {
            $data = $this->validatedInput();
            $membership = $action->execute($data, (bool) $this->option('allow-existing-owner'));
            $user = $membership->user;
            $company = $membership->company;
            $branch = Branch::query()
                ->where('company_id', $company->id)
                ->where('name', trim($data['branch']))
                ->where('is_active', true)
                ->firstOrFail();

            if (! Auth::validate(['email' => $user->email, 'password' => $data['password']])) {
                throw new DomainException('La cuenta fue localizada, pero no superó la validación de autenticación.');
            }

            $hasAdministrativeAccess = collect(Permission::cases())
                ->every(fn (Permission $permission): bool => $user->canForCompany($permission, $company));

            if (! $membership->isActiveOwner() || ! $hasAdministrativeAccess) {
                throw new DomainException('La membership no concede acceso administrativo completo.');
            }

            $this->components->info('Owner de producción aprovisionado y verificado.');
            $this->table(
                ['Nombre', 'Correo', 'Rol', 'Empresa', 'Sucursal', 'Estado'],
                [[$user->name, $user->email, $membership->role->value, $company->name, $branch->name, 'Activo']],
            );

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        } catch (DomainException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('No se pudo completar el aprovisionamiento. No se mostraron datos sensibles.');

            return self::FAILURE;
        }
    }

    /** @return array{name:string,email:string,password:string,password_confirmation:string,company:string,branch:string} */
    private function validatedInput(): array
    {
        $data = [
            'name' => $this->textValue('PROVISION_OWNER_NAME', 'Nombre del owner'),
            'email' => $this->textValue('PROVISION_OWNER_EMAIL', 'Correo del owner'),
            'company' => $this->textValue('PROVISION_OWNER_COMPANY', 'Nombre de la empresa'),
            'branch' => $this->textValue('PROVISION_OWNER_BRANCH', 'Nombre de la sucursal principal'),
            'password' => $this->secretValue('PROVISION_OWNER_PASSWORD', 'Contraseña del owner'),
            'password_confirmation' => $this->secretValue('PROVISION_OWNER_PASSWORD_CONFIRMATION', 'Confirmar contraseña'),
        ];

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], [
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ])->validate();
    }

    private function textValue(string $environmentKey, string $question): string
    {
        $value = getenv($environmentKey);
        if (is_string($value) && filled(trim($value))) {
            return trim($value);
        }

        if (! $this->input->isInteractive()) {
            throw new DomainException('Falta la variable temporal '.$environmentKey.'.');
        }

        return trim((string) $this->ask($question));
    }

    private function secretValue(string $environmentKey, string $question): string
    {
        $value = getenv($environmentKey);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! $this->input->isInteractive()) {
            throw new DomainException('Falta la variable temporal '.$environmentKey.'.');
        }

        return (string) $this->secret($question);
    }
}
