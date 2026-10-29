<?php

namespace App\Console\Commands;

use App\Actions\SaveBancoEconomicoSettingAction;
use App\Models\Branch;
use App\Models\Company;
use DomainException;
use Illuminate\Console\Command;

class ConfigureBancoEconomicoCommand extends Command
{
    protected $signature = 'baneco:configure {company : ULID de empresa} {--branch= : ULID de sucursal; vacío aplica a toda la empresa}';

    protected $description = 'Guarda una versión cifrada de las credenciales sin realizar solicitudes bancarias';

    public function handle(SaveBancoEconomicoSettingAction $save): int
    {
        $company = Company::query()->where('ulid', $this->argument('company'))->firstOrFail();
        $branch = $this->option('branch') ? Branch::query()->forCompany($company)->where('ulid', $this->option('branch'))->firstOrFail() : null;
        $credentials = [];
        foreach (['username' => 'Usuario API', 'password' => 'Contraseña API', 'aes_key' => 'Llave AES', 'account_credit' => 'Cuenta de abono'] as $key => $label) {
            $value = $this->secret($label);
            if (! is_string($value) || trim($value) === '') {
                $this->error('Todos los datos bancarios son obligatorios.');

                return self::FAILURE;
            }
            $credentials[$key] = $value;
        }
        $credentials['branch_code'] = $this->ask('branchCode bancario (opcional)', '');
        $environment = $this->choice('Ambiente bancario', ['production', 'certification'], 'certification');
        $credentials += ['environment' => $environment, 'base_url' => SaveBancoEconomicoSettingAction::URLS[$environment],
            'due_days' => $this->ask('Días de vencimiento', '1'), 'enabled' => $this->confirm('¿Habilitar cobros QR para esta configuración?', false),
            'technical_logging' => $this->confirm('¿Habilitar registro técnico sanitizado?', true)];
        try {
            $save->execute($company, $branch, null, $credentials);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Configuración cifrada guardada. No se contactó al banco.');

        return self::SUCCESS;
    }
}
