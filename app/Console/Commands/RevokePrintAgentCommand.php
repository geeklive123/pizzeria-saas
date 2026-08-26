<?php

namespace App\Console\Commands;

use App\Models\PrintAgent;
use Illuminate\Console\Command;

class RevokePrintAgentCommand extends Command
{
    protected $signature = 'print-agent:revoke {agent} {--force}';

    protected $description = 'Revoca un agente de impresión y su token';

    public function handle(): int
    {
        $agent = PrintAgent::query()->where('ulid', $this->argument('agent'))->firstOrFail();
        if (! $this->option('force') && ! $this->confirm('Revocar el agente '.$agent->name.'?')) {
            return self::FAILURE;
        }

        $agent->update(['is_active' => false, 'revoked_at' => now()]);
        $this->info('Agente revocado.');

        return self::SUCCESS;
    }
}
