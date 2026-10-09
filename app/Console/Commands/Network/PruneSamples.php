<?php

namespace App\Console\Commands\Network;

use App\Services\Network\MonitoringService;
use Illuminate\Console\Command;

class PruneSamples extends Command
{
    protected $signature = 'network:prune {--days=30 : Conservar los últimos N días}';

    protected $description = 'Elimina muestras de monitoreo y eventos de conexión antiguos';

    public function handle(MonitoringService $monitoring): int
    {
        $r = $monitoring->prune(max(1, (int) $this->option('days')));
        $this->info("Eliminadas {$r['samples']} muestras y {$r['events']} eventos de conexión");

        return self::SUCCESS;
    }
}
