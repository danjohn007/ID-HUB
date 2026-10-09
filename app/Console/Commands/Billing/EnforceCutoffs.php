<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\CollectionAutomation;

class EnforceCutoffs extends BillingCommand
{
    protected $signature = 'billing:enforce-cutoffs {--tenant= : Solo este WISP} {--force : Ignora la hora de corte} {--dry-run : Solo cuenta, no dispara}';

    protected $description = 'Dispara "pago vencido" (corte automático) para clientes fuera de tolerancia, una vez por cliente por día';

    public function handle(CollectionAutomation $automation): int
    {
        $this->info('Corte automático por falta de pago'.($this->option('dry-run') ? ' (simulación)' : ''));
        foreach ($this->tenants() as $tenant) {
            try {
                $this->line_stats($tenant, $automation->enforceCutoffs($tenant, (bool) $this->option('force'), (bool) $this->option('dry-run')));
            } catch (\Throwable $e) {
                report($e);
                $this->error("  #{$tenant->id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
