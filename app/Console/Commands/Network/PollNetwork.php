<?php

namespace App\Console\Commands\Network;

use App\Models\Tenant;
use App\Services\Network\MonitoringService;
use Illuminate\Console\Command;

/**
 * Ciclo de monitoreo de red: MikroTik → métricas, clientes en línea, repetidores, fallas y alertas.
 * Programado cada 5 minutos (routes/schedule/network.php). Idempotente.
 */
class PollNetwork extends Command
{
    protected $signature = 'network:poll {--tenant= : ID del WISP (por defecto todos los activos)}';

    protected $description = 'Consulta los MikroTik, actualiza clientes/repetidores y detecta fallas y anomalías de sesión';

    public function handle(MonitoringService $monitoring): int
    {
        $tenants = Tenant::query()->whereIn('status', ['active', 'trial'])
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            $start = microtime(true);
            try {
                $s = $monitoring->pollTenant($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->name}: {$e->getMessage()}");

                continue;
            }
            $this->info(sprintf('%s: %d MikroTik OK, %d con error · %d clientes en línea (+%d / -%d) · fallas abiertas %d, cerradas %d · %d alertas nuevas · %d muestras · %.1fs',
                $tenant->name, $s['routers_ok'], $s['routers_failed'], $s['clients_online'], $s['came_online'], $s['went_offline'],
                $s['outages_opened'], $s['outages_closed'], $s['alerts'], $s['samples'], microtime(true) - $start));
            foreach ($s['errors'] as $err) {
                $this->warn('  '.$err);
            }
        }

        return self::SUCCESS;
    }
}
