<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\CollectionAutomation;

class SendReminders extends BillingCommand
{
    protected $signature = 'billing:reminders {--tenant= : Solo este WISP} {--force : Ignora la hora de envío} {--dry-run : Solo cuenta, no envía}';

    protected $description = 'Recordatorios previos, avisos de vencimiento y de suspensión (una vez por cargo) y marca morosos';

    public function handle(CollectionAutomation $automation): int
    {
        $this->info('Recordatorios de cobranza'.($this->option('dry-run') ? ' (simulación)' : ''));
        foreach ($this->tenants() as $tenant) {
            try {
                $this->line_stats($tenant, $automation->sendReminders($tenant, (bool) $this->option('force'), (bool) $this->option('dry-run')));
            } catch (\Throwable $e) {
                report($e);
                $this->error("  #{$tenant->id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
