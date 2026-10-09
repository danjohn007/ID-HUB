<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\CollectionAutomation;
use Carbon\Carbon;

class GenerateCharges extends BillingCommand
{
    protected $signature = 'billing:generate-charges {--tenant= : Solo este WISP} {--date= : Fecha (Y-m-d), por defecto hoy en la zona del WISP} {--dry-run : No crea cargos}';

    protected $description = 'Genera las mensualidades del día para cada WISP activo (idempotente por periodo)';

    public function handle(CollectionAutomation $automation): int
    {
        $this->info('Generación de cargos'.($this->option('dry-run') ? ' (simulación)' : ''));
        foreach ($this->tenants() as $tenant) {
            try {
                $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : null;
                $this->line_stats($tenant, $automation->generateCharges($tenant, $date, (bool) $this->option('dry-run')));
            } catch (\Throwable $e) {
                report($e);
                $this->error("  #{$tenant->id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
