<?php

namespace App\Console\Commands;

use App\Services\SaasBillingService;
use Illuminate\Console\Command;

/**
 * Vigencia de las suscripciones SaaS (sección 48): suspende los WISP cuya prueba terminó o cuya
 * suscripción venció hace más de los días de gracia (WISP_SAAS_GRACE_DAYS). Idempotente.
 *   php artisan saas:subscriptions
 */
class EnforceSubscriptions extends Command
{
    protected $signature = 'saas:subscriptions {--dry-run : Solo muestra qué WISP se suspenderían}';

    protected $description = 'Suspende los WISP con prueba terminada o suscripción vencida';

    public function handle(SaasBillingService $billing): int
    {
        $tenants = $billing->enforceSubscriptions((bool) $this->option('dry-run'));
        foreach ($tenants as $line) {
            $this->line($line);
        }
        $this->info('WISP suspendidos: '.count($tenants));

        return self::SUCCESS;
    }
}
