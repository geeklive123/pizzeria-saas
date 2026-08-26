<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PrintAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionPrintAgentCommand extends Command
{
    protected $signature = 'print-agent:provision {--company=} {--branch=} {--name=Windows Epson} {--rotate} {--force}';

    protected $description = 'Provisiona o rota de forma segura el token de un agente de impresión';

    public function handle(): int
    {
        $companyRef = (string) ($this->option('company') ?: $this->ask('ULID o nombre exacto de la empresa'));
        $company = Company::query()->where('ulid', $companyRef)->orWhere('name', $companyRef)->sole();
        $branchRef = (string) ($this->option('branch') ?: $this->ask('ULID o nombre exacto de la sucursal'));
        $branch = Branch::query()->forCompany($company)->where(function ($query) use ($branchRef): void {
            $query->where('ulid', $branchRef)->orWhere('name', $branchRef);
        })->sole();
        $name = trim((string) $this->option('name'));

        $agent = PrintAgent::query()->forCompany($company)->forBranch($branch)->where('name', $name)->first();
        if ($agent && ! $this->option('rotate')) {
            $this->error('El agente ya existe. Usa --rotate para reemplazar su token de forma explícita.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Provisionar agente '.$name.' para '.$company->name.' / '.$branch->name.'?')) {
            return self::FAILURE;
        }

        $token = Str::random(80);
        $values = ['token_hash' => hash('sha256', $token), 'is_active' => true, 'revoked_at' => null];
        if ($agent) {
            $agent->update($values);
        } else {
            $agent = PrintAgent::query()->create($values + [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'name' => $name,
            ]);
        }

        $this->info('Agente listo. Copia este token ahora; no volverá a mostrarse:');
        $this->line($token);
        $this->newLine();
        $this->warn('Guárdalo únicamente en el .env local de la PC Windows.');
        $this->line('Agente: '.$agent->ulid);

        return self::SUCCESS;
    }
}
