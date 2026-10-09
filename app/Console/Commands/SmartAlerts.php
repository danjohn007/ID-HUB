<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Alerts\SmartAlertsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Cada hora: alertas inteligentes por análisis (cancelaciones, crecimiento por zona,
 * desconexiones masivas, retrasos recurrentes) y auto-resolución.
 */
class SmartAlerts extends Command
{
    protected $signature = 'alerts:smart {--tenant= : ID de un WISP específico}';

    protected $description = 'Genera y resuelve alertas inteligentes (sección 45) por WISP';

    public function handle(TenantContext $context): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            try {
                $result = $context->run($tenant, fn () => app(SmartAlertsService::class)->run());
                $summary = collect($result)->map(fn ($r, $type) => "$type +{$r['raised']}/-{$r['resolved']}")->implode(', ');
                $this->line("{$tenant->name}: {$summary}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
