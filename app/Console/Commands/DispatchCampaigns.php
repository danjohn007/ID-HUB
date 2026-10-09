<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Messaging\CampaignService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Cada minuto: inicia campañas programadas vencidas, envía el siguiente lote de las campañas
 * en curso y actualiza contadores. Lotes acotados para hosting compartido.
 */
class DispatchCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch {--budget=400 : Máximo de mensajes a crear por WISP en esta ejecución}';

    protected $description = 'Envía campañas programadas y procesa lotes de campañas en curso';

    public function handle(CampaignService $service, TenantContext $context): int
    {
        $budget = max(1, (int) $this->option('budget'));

        Tenant::query()->where('status', 'active')->each(function (Tenant $tenant) use ($service, $context, $budget) {
            try {
                $r = $context->run($tenant, fn () => $service->tick($budget));
                if ($r['started'] || $r['messages']) {
                    $this->line("{$tenant->name}: {$r['started']} campañas iniciadas, {$r['messages']} mensajes en cola");
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
