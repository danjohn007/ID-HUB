<?php

namespace App\Console\Commands\Network;

use App\Models\Tenant;
use App\Services\Network\MonitoringService;
use Illuminate\Console\Command;

/**
 * Detección de clientes con posibles anomalías (sección 15): IP duplicada, consumo anormal y
 * desconexiones frecuentes. Genera alertas para revisión técnica (no asume fraude).
 */
class DetectAnomalies extends Command
{
    protected $signature = 'network:anomalies {--tenant=}';

    protected $description = 'Detecta IP duplicada, consumo anormal y desconexiones frecuentes (alertas para revisión técnica)';

    public function handle(MonitoringService $monitoring): int
    {
        $tenants = Tenant::query()->whereIn('status', ['active', 'trial'])
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($tenants as $tenant) {
            $found = $monitoring->detectClientAnomalies($tenant);
            $this->info("{$tenant->name}: IP duplicada {$found['anomaly_duplicate_ip']} · consumo anormal {$found['anomaly_usage']} · desconexiones frecuentes {$found['anomaly_flapping']}");
        }

        return self::SUCCESS;
    }
}
