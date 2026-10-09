<?php

namespace App\Services;

use App\Models\SaasInvoice;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Facturación de la plataforma SaaS (secciones 48 y 49):
 *  - Comisiones por pagos en línea de cada WISP (gateway + WISPCONTROL + neto WISP).
 *  - Factura mensual por WISP = mensualidad del plan + comisiones de plataforma del mes.
 * Consultas sin scope de tenant (panel del superadministrador / consola).
 */
class SaasBillingService
{
    /** Pagos en línea por WISP en el rango: [tenant_id => {count, amount, gateway_fee, platform_fee, net}] */
    public function onlinePayments(Carbon $from, Carbon $to, ?int $tenantId = null): Collection
    {
        return DB::table('payments')->where('status', 'completed')->where('kind', 'payment')
            ->whereBetween('paid_at', [$from, $to])
            ->where(fn ($q) => $q->whereNotNull('gateway')->orWhereIn('method', array_keys(config('wisp.gateways'))))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('tenant_id, COUNT(*) n, SUM(amount) amount, SUM(gateway_fee) gateway_fee, SUM(platform_fee) platform_fee, SUM(net_amount) net')
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');
    }

    /** Comisiones de plataforma por mes (últimos N meses) para gráficas. */
    public function platformFeesByMonth(int $months = 12, ?int $tenantId = null): Collection
    {
        $from = now()->startOfMonth()->subMonths($months - 1);

        return DB::table('payments')->where('status', 'completed')->where('kind', 'payment')->where('paid_at', '>=', $from)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') ym, SUM(platform_fee) fees, SUM(CASE WHEN platform_fee > 0 THEN amount ELSE 0 END) online")
            ->groupBy('ym')->get()->keyBy('ym');
    }

    public function folio(Tenant $tenant, Carbon $month): string
    {
        return 'SAAS-'.$tenant->id.'-'.$month->format('Ym');
    }

    /**
     * Genera (o recalcula si sigue pendiente) la factura del mes para un WISP. Idempotente:
     * una factura por WISP y periodo; las facturas pagadas o canceladas no se modifican.
     */
    public function generate(Tenant $tenant, Carbon $month): ?SaasInvoice
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $plan = $tenant->saasPlan;
        if (! $plan || $tenant->created_at?->gt($end)) {
            return null;
        }

        $existing = SaasInvoice::query()->where('tenant_id', $tenant->id)->whereDate('period_start', $start->toDateString())->first();
        if ($existing && $existing->status !== 'pending') {
            return $existing;
        }

        $commissions = round((float) ($this->onlinePayments($start, $end, $tenant->id)->get($tenant->id)->platform_fee ?? 0), 2);
        $amount = (float) $plan->price_monthly;
        $values = [
            'saas_plan_id' => $plan->id, 'folio' => $this->folio($tenant, $start),
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'amount' => $amount, 'commissions' => $commissions, 'total' => round($amount + $commissions, 2),
        ];

        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return SaasInvoice::create($values + ['tenant_id' => $tenant->id, 'status' => 'pending']);
    }

    /** Genera las facturas del mes para todos los WISP con plan (no cancelados). Devuelve [creadas/actualizadas, omitidas]. */
    public function generateAll(Carbon $month): array
    {
        $done = 0;
        $skipped = 0;
        Tenant::query()->with('saasPlan')->whereIn('status', ['active', 'trial', 'suspended'])->orderBy('id')
            ->each(function (Tenant $t) use ($month, &$done, &$skipped) {
                $this->generate($t, $month) ? $done++ : $skipped++;
            });

        return [$done, $skipped];
    }

    public function markPaid(SaasInvoice $invoice): void
    {
        if ($invoice->status !== 'paid') {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        }
    }

    /** MRR de la plataforma: suma de la mensualidad de los planes de WISP activos. */
    public function mrr(): float
    {
        return (float) DB::table('tenants')->join('saas_plans', 'saas_plans.id', '=', 'tenants.saas_plan_id')
            ->whereNull('tenants.deleted_at')->where('tenants.status', 'active')->sum('saas_plans.price_monthly');
    }
}
