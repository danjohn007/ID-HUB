<?php

namespace App\Console\Commands\Network;

use App\Models\Tenant;
use App\Services\Network\MaintenanceService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Aviso anticipado de mantenimientos programados y cambio automático de estado. */
class NotifyMaintenances extends Command
{
    protected $signature = 'network:maintenances';

    protected $description = 'Notifica mantenimientos próximos (según horas de anticipación) y actualiza su estado';

    public function handle(MaintenanceService $service): int
    {
        foreach (Tenant::query()->whereIn('status', ['active', 'trial'])->get() as $tenant) {
            $sent = app(TenantContext::class)->run($tenant, function () use ($service) {
                $service->syncStatuses();

                return $service->notifyDue();
            });
            $this->info("{$tenant->name}: {$sent} avisos de mantenimiento enviados");
        }

        return self::SUCCESS;
    }
}
