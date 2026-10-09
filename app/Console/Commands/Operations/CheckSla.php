<?php

namespace App\Console\Commands\Operations;

use App\Models\Tenant;
use App\Services\SlaMonitor;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Revisa el SLA de los tickets abiertos de cada WISP activo (programado cada 5 minutos).
 */
class CheckSla extends Command
{
    protected $signature = 'operations:check-sla {--tenant= : ID de un WISP específico}';

    protected $description = 'Genera alertas de tickets próximos a vencer / vencidos según la política SLA de cada WISP';

    public function handle(TenantContext $context): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $stats = $context->run($tenant, fn () => app(SlaMonitor::class)->check());
                $this->line(sprintf('%s: %d en riesgo, %d vencidos, %d alertas resueltas', $tenant->name, $stats['warned'], $stats['breached'], $stats['resolved']));
            } catch (\Throwable $e) {
                report($e);
                $this->error($tenant->name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
