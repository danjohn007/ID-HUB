<?php

namespace App\Console\Commands;

use App\Services\SaasBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Genera la factura SaaS mensual de cada WISP (plan + comisiones de plataforma).
 * Por defecto factura el mes anterior. Idempotente: no duplica ni modifica facturas pagadas.
 *   php artisan saas:invoices
 *   php artisan saas:invoices --month=2026-09
 */
class GenerateSaasInvoices extends Command
{
    protected $signature = 'saas:invoices {--month= : Mes a facturar (AAAA-MM). Por defecto, el mes anterior}';

    protected $description = 'Genera las facturas mensuales de suscripción y comisiones de cada WISP';

    public function handle(SaasBillingService $billing): int
    {
        try {
            $month = $this->option('month')
                ? Carbon::createFromFormat('Y-m-d', $this->option('month').'-01')->startOfMonth()
                : now()->subMonthNoOverflow()->startOfMonth();
        } catch (\Throwable) {
            $this->error('Formato de mes inválido. Usa AAAA-MM.');

            return self::FAILURE;
        }
        if ($month->gt(now())) {
            $this->error('No se puede facturar un mes futuro.');

            return self::FAILURE;
        }

        [$done, $skipped] = $billing->generateAll($month);
        $this->info("Facturas SaaS de {$month->translatedFormat('F Y')}: {$done} generadas/actualizadas, {$skipped} omitidas (sin plan).");

        return self::SUCCESS;
    }
}
