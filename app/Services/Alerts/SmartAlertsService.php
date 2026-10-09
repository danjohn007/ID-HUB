<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\AlertService;
use App\Services\MetricsService;
use App\Services\RiskService;

/**
 * Alertas inteligentes (sección 45) que requieren análisis periódico. Se ejecuta cada hora
 * por WISP (comando alerts:smart) dentro del contexto del tenant. Es idempotente: AlertService
 * deduplica por dedupe_key y las alertas cuya condición ya no se cumple se resuelven solas.
 *
 *  - churn_spike      Aumento anormal de cancelaciones (churn del mes vs. promedio de 6 meses).
 *  - zone_growth      Zona con crecimiento acelerado (altas + prospectos 30 días vs. 30 previos).
 *  - mass_disconnect  Muchos clientes desconectados en un repetidor (umbral network.mass_disconnect_threshold).
 *  - recurrent_delay  Cliente con retrasos recurrentes (RiskService::refresh genera la alerta).
 */
class SmartAlertsService
{
    /** Mínimo de altas+prospectos en 30 días para considerar "crecimiento acelerado". */
    public const ZONE_MIN_GROWTH = 5;

    public const ZONE_GROWTH_FACTOR = 1.5;

    public function __construct(
        protected AlertService $alerts,
        protected MetricsService $metrics,
        protected RiskService $risk,
    ) {}

    /** @return array<string, array{raised: int, resolved: int}> */
    public function run(): array
    {
        return [
            'churn_spike' => $this->churnSpike(),
            'zone_growth' => $this->zoneGrowth(),
            'mass_disconnect' => $this->massDisconnect(),
            'recurrent_delay' => $this->recurrentDelays(),
        ];
    }

    public function churnSpike(): array
    {
        $current = $this->metrics->churn();
        $average = $this->metrics->averageChurn(6);
        $cancelled = Client::query()->whereBetween('cancelled_at', [now()->startOfMonth(), now()])->count();
        $key = 'churn_spike:'.now()->format('Y-m');

        $threshold = max($average * 1.5, $average + 1.0);
        $raised = 0;
        if ($cancelled >= 3 && $current > $threshold) {
            $severity = $current >= max($average * 2, $average + 2) ? 'critical' : 'warning';
            $this->alerts->raise('churn_spike', $severity, 'Aumento anormal de cancelaciones',
                "Churn del mes {$this->pct($current)} vs. promedio de 6 meses {$this->pct($average)} · {$cancelled} cancelaciones",
                null, ['churn' => $current, 'average' => $average, 'cancelled' => $cancelled], $key);
            $raised = 1;
        }

        $resolved = Alert::query()->open()->where('type', 'churn_spike')
            ->when($raised, fn ($q) => $q->where('dedupe_key', '!=', $key))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        return ['raised' => $raised, 'resolved' => $resolved];
    }

    public function zoneGrowth(): array
    {
        $now = now();
        $from = $now->copy()->subDays(30);
        $prevFrom = $now->copy()->subDays(60);

        $count = function ($start, $end) {
            $clients = Client::query()->whereNotNull('zone_id')
                ->whereBetween('installation_date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('zone_id, COUNT(*) n')->groupBy('zone_id')->pluck('n', 'zone_id');
            $prospects = Prospect::query()->whereNotNull('zone_id')->whereBetween('created_at', [$start, $end])
                ->selectRaw('zone_id, COUNT(*) n')->groupBy('zone_id')->pluck('n', 'zone_id');

            return ['clients' => $clients, 'prospects' => $prospects];
        };
        $cur = $count($from, $now);
        $prev = $count($prevFrom, $from);

        $raised = $resolved = 0;
        foreach (Zone::query()->get() as $zone) {
            $c = (int) ($cur['clients'][$zone->id] ?? 0);
            $p = (int) ($cur['prospects'][$zone->id] ?? 0);
            $total = $c + $p;
            $before = (int) ($prev['clients'][$zone->id] ?? 0) + (int) ($prev['prospects'][$zone->id] ?? 0);

            if ($total >= self::ZONE_MIN_GROWTH && $total >= max(1, $before) * self::ZONE_GROWTH_FACTOR) {
                $change = $before > 0 ? '+'.round(($total - $before) / $before * 100).'%' : 'sin actividad previa';
                $this->alerts->raise('zone_growth', 'info', "Zona {$zone->name} con crecimiento acelerado",
                    "{$c} altas y {$p} prospectos en 30 días ({$change} vs. 30 días previos)",
                    $zone, ['current' => $total, 'previous' => $before, 'clients' => $c, 'prospects' => $p]);
                $raised++;
            } else {
                $resolved += $this->alerts->resolveFor('zone_growth', $zone);
            }
        }

        return ['raised' => $raised, 'resolved' => $resolved];
    }

    public function massDisconnect(): array
    {
        $threshold = max(1, (int) tenant()->setting('network.mass_disconnect_threshold', 15));
        $offline = Client::query()->whereIn('service_status', MetricsService::LIVE)->where('online', false)->whereNotNull('repeater_id')
            ->selectRaw('repeater_id, COUNT(*) n')->groupBy('repeater_id')->pluck('n', 'repeater_id');

        $raised = $resolved = 0;
        foreach (Repeater::query()->get() as $repeater) {
            $n = (int) ($offline[$repeater->id] ?? 0);
            // Un repetidor caído ya genera su propia alerta (repeater_down)
            if ($repeater->status !== 'offline' && $n >= $threshold) {
                $severity = $n >= $threshold * 2 ? 'critical' : 'warning';
                $this->alerts->raise('mass_disconnect', $severity, "{$n} clientes desconectados en {$repeater->code}",
                    "El repetidor {$repeater->code} · {$repeater->name} tiene {$n} clientes sin conexión (umbral {$threshold}).",
                    $repeater, ['offline' => $n, 'threshold' => $threshold]);
                $raised++;
            } else {
                $resolved += $this->alerts->resolveFor('mass_disconnect', $repeater);
            }
        }

        return ['raised' => $raised, 'resolved' => $resolved];
    }

    /**
     * Recalcula el riesgo de clientes con movimiento reciente de cobranza (pagos tardíos o cargos
     * que acaban de vencer). RiskService::refresh genera la alerta al pasar a riesgo alto.
     * Resuelve las alertas de clientes que ya no están en riesgo alto.
     */
    public function recurrentDelays(): array
    {
        $since = now()->subDay();
        $ids = Charge::query()->where('type', 'mensualidad')
            ->where(function ($q) use ($since) {
                $q->where(fn ($w) => $w->where('paid_at', '>=', $since)->whereRaw('DATE(paid_at) > due_date'))
                    ->orWhere(fn ($w) => $w->open()->whereBetween('due_date', [$since->toDateString(), now()->toDateString()]));
            })
            ->distinct()->limit(500)->pluck('client_id');

        $before = Alert::query()->open()->where('type', 'recurrent_delay')->count();
        Client::query()->whereIn('id', $ids)->with('plan')->get()->each(fn ($c) => $this->risk->refresh($c));
        $raised = max(0, Alert::query()->open()->where('type', 'recurrent_delay')->count() - $before);

        $resolved = 0;
        $open = Alert::query()->open()->where('type', 'recurrent_delay')->where('subject_type', 'client')->get();
        $clients = Client::withTrashed()->whereIn('id', $open->pluck('subject_id'))->get()->keyBy('id');
        foreach ($open as $alert) {
            $client = $clients[$alert->subject_id] ?? null;
            if (! $client || $client->trashed() || $client->risk_level !== 'alto' || ! in_array($client->service_status, Client::BILLABLE, true)) {
                $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
                $resolved++;
            }
        }

        return ['raised' => $raised, 'resolved' => $resolved];
    }

    protected function pct(float $v): string
    {
        return number_format($v, 1).'%';
    }
}
