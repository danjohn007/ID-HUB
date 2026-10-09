<?php

namespace App\Services\Analytics;

use App\Models\Plan;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\MetricsService;
use App\Support\MapData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business Intelligence del WISP activo (sección 47):
 *  - Rentabilidad por zona y por repetidor (ingresos cobrados vs costos operativos).
 *  - Crecimiento (altas, bajas, neto, MRR).
 *  - Análisis de expansión: prospectos + cobertura + distancia + capacidad + clientes existentes.
 * Todas las consultas filtran por el tenant del contexto. Compatible con MySQL 5.7.
 */
class BiService
{
    public const LIVE = MetricsService::LIVE;

    public function __construct(protected MetricsService $metrics) {}

    protected function tid(): int
    {
        return (int) tenant()->id;
    }

    public static function periods(): array
    {
        return [
            'mes_anterior' => 'Mes anterior',
            'mes' => 'Mes actual',
            'trimestre' => 'Últimos 3 meses',
            'anio' => 'Últimos 12 meses',
        ];
    }

    /** [desde, hasta, meses] para un periodo de la lista. */
    public function range(string $period): array
    {
        return match ($period) {
            'mes' => [now()->startOfMonth(), now(), 1],
            'trimestre' => [now()->startOfMonth()->subMonths(2), now(), 3],
            'anio' => [now()->startOfMonth()->subMonths(11), now(), 12],
            default => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), 1],
        };
    }

    // ------------------------------------------------------------------ Zonas

    public function zones(Carbon $from, Carbon $to, int $months): array
    {
        $tid = $this->tid();

        $status = DB::table('clients')->where('tenant_id', $tid)->whereNull('deleted_at')
            ->selectRaw('zone_id, service_status, COUNT(*) n')->groupBy('zone_id', 'service_status')->get()->groupBy('zone_id');
        $mrr = DB::table('clients')->join('plans', 'plans.id', '=', 'clients.plan_id')
            ->where('clients.tenant_id', $tid)->whereNull('clients.deleted_at')->whereIn('clients.service_status', self::LIVE)
            ->selectRaw("clients.zone_id, SUM(plans.price / CASE plans.periodicity WHEN 'bimonthly' THEN 2 WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END) v")
            ->groupBy('clients.zone_id')->pluck('v', 'zone_id');
        $revenue = DB::table('payments')->join('clients', 'clients.id', '=', 'payments.client_id')
            ->where('payments.tenant_id', $tid)->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->selectRaw('clients.zone_id, SUM(payments.amount) v')->groupBy('clients.zone_id')->pluck('v', 'zone_id');
        $cancels = DB::table('clients')->where('tenant_id', $tid)->whereNull('deleted_at')->whereBetween('cancelled_at', [$from, $to])
            ->selectRaw('zone_id, COUNT(*) v')->groupBy('zone_id')->pluck('v', 'zone_id');
        $adds = DB::table('clients')->where('tenant_id', $tid)->whereNull('deleted_at')
            ->whereBetween('installation_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('zone_id, COUNT(*) v')->groupBy('zone_id')->pluck('v', 'zone_id');
        $overdue = DB::table('charges')->join('clients', 'clients.id', '=', 'charges.client_id')
            ->where('charges.tenant_id', $tid)->whereIn('charges.status', ['pending', 'partial'])->where('charges.due_date', '<', now()->toDateString())
            ->selectRaw('clients.zone_id, SUM(charges.total - charges.paid_amount) v')->groupBy('clients.zone_id')->pluck('v', 'zone_id');
        $towerCost = DB::table('towers')->where('tenant_id', $tid)->selectRaw('zone_id, SUM(monthly_cost) v')->groupBy('zone_id')->pluck('v', 'zone_id');
        $repeaterCost = DB::table('repeaters')->where('tenant_id', $tid)->selectRaw('zone_id, SUM(monthly_cost) v, COUNT(*) n')->groupBy('zone_id')->get()->keyBy('zone_id');

        $zones = Zone::query()->orderBy('name')->get(['id', 'name', 'color', 'monthly_cost'])
            ->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'color' => $z->color, 'zone_cost' => (float) $z->monthly_cost]);
        if ($status->has('') || $status->has(null) || $revenue->has('')) {
            $zones->push(['id' => null, 'name' => 'Sin zona', 'color' => '#94A3B8', 'zone_cost' => 0.0]);
        }

        return $zones->map(function ($z) use ($status, $mrr, $revenue, $cancels, $adds, $overdue, $towerCost, $repeaterCost, $months) {
            $k = $z['id'] ?? '';
            $counts = collect($status->get($k, []))->pluck('n', 'service_status');
            $live = collect(self::LIVE)->sum(fn ($s) => (int) ($counts[$s] ?? 0));
            $billable = collect(\App\Models\Client::BILLABLE)->sum(fn ($s) => (int) ($counts[$s] ?? 0));
            $delinquent = (int) ($counts['moroso'] ?? 0) + (int) ($counts['suspendido'] ?? 0);
            $monthlyCost = $z['zone_cost'] + (float) ($towerCost[$k] ?? 0) + (float) ($repeaterCost[$k]->v ?? 0);
            $cost = $monthlyCost * $months;
            $rev = (float) ($revenue[$k] ?? 0);
            $base = $live + (int) ($cancels[$k] ?? 0);

            return $z + [
                'clients' => $live,
                'billable' => $billable,
                'repeaters' => (int) ($repeaterCost[$k]->n ?? 0),
                'mrr' => round((float) ($mrr[$k] ?? 0), 2),
                'revenue' => round($rev, 2),
                'monthly_cost' => round($monthlyCost, 2),
                'cost' => round($cost, 2),
                'margin' => round($rev - $cost, 2),
                'margin_pct' => $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : null,
                'adds' => (int) ($adds[$k] ?? 0),
                'cancels' => (int) ($cancels[$k] ?? 0),
                'churn' => $base > 0 ? round((int) ($cancels[$k] ?? 0) / $base * 100 / max(1, $months), 2) : 0,
                'delinquent' => $delinquent,
                'delinquency' => $billable > 0 ? round($delinquent / $billable * 100, 1) : 0,
                'overdue' => round((float) ($overdue[$k] ?? 0), 2),
                'arpu' => $live > 0 ? round((float) ($mrr[$k] ?? 0) / $live, 2) : 0,
            ];
        })->values()->all();
    }

    // ------------------------------------------------------------- Repetidores

    public function repeaters(Carbon $from, Carbon $to, int $months): array
    {
        $tid = $this->tid();
        $revenue = DB::table('payments')->join('clients', 'clients.id', '=', 'payments.client_id')
            ->where('payments.tenant_id', $tid)->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->selectRaw('clients.repeater_id, SUM(payments.amount) v')->groupBy('clients.repeater_id')->pluck('v', 'repeater_id');
        $mrr = DB::table('clients')->join('plans', 'plans.id', '=', 'clients.plan_id')
            ->where('clients.tenant_id', $tid)->whereNull('clients.deleted_at')->whereIn('clients.service_status', self::LIVE)
            ->selectRaw("clients.repeater_id, SUM(plans.price / CASE plans.periodicity WHEN 'bimonthly' THEN 2 WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END) v")
            ->groupBy('clients.repeater_id')->pluck('v', 'repeater_id');
        $delinquent = DB::table('clients')->where('tenant_id', $tid)->whereNull('deleted_at')->whereIn('service_status', ['moroso', 'suspendido'])
            ->selectRaw('repeater_id, COUNT(*) v')->groupBy('repeater_id')->pluck('v', 'repeater_id');
        // El costo de la torre se reparte entre sus repetidores
        $perTower = DB::table('repeaters')->where('tenant_id', $tid)->whereNotNull('tower_id')
            ->selectRaw('tower_id, COUNT(*) n')->groupBy('tower_id')->pluck('n', 'tower_id');
        $towerCost = DB::table('towers')->where('tenant_id', $tid)->pluck('monthly_cost', 'id');

        return Repeater::query()->withLoad()->with(['zone:id,name', 'tower:id,code'])->orderBy('code')->get()
            ->map(function ($r) use ($revenue, $mrr, $delinquent, $perTower, $towerCost, $months) {
                $towerShare = $r->tower_id && ($perTower[$r->tower_id] ?? 0) ? (float) ($towerCost[$r->tower_id] ?? 0) / $perTower[$r->tower_id] : 0;
                $monthlyCost = (float) $r->monthly_cost + $towerShare;
                $rev = (float) ($revenue[$r->id] ?? 0);
                $cost = $monthlyCost * $months;
                $load = $r->clientLoad();
                $util = $r->utilization();

                return [
                    'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'zone' => $r->zone?->name, 'tower' => $r->tower?->code,
                    'status' => $r->status,
                    'clients' => $load, 'online' => (int) $r->online_clients_count, 'capacity' => (int) $r->capacity,
                    'utilization' => $util, 'threshold' => (int) $r->alert_threshold,
                    'saturated' => $util >= $r->alert_threshold, 'spare' => max(0, (int) $r->capacity - $load),
                    'mrr' => round((float) ($mrr[$r->id] ?? 0), 2),
                    'revenue' => round($rev, 2),
                    'monthly_cost' => round($monthlyCost, 2),
                    'cost' => round($cost, 2),
                    'margin' => round($rev - $cost, 2),
                    'margin_pct' => $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : null,
                    'per_client' => $load > 0 ? round($rev / $months / $load, 2) : 0,
                    'delinquent' => (int) ($delinquent[$r->id] ?? 0),
                ];
            })->all();
    }

    // -------------------------------------------------------------- Crecimiento

    public function growth(int $months = 12): array
    {
        $series = collect($this->metrics->monthlySeries($months))->keyBy('key');
        $mrr = collect($this->metrics->mrrTrend($months))->keyBy('key');

        return $mrr->map(function ($m, $key) use ($series, $mrr) {
            $s = $series->get($key, ['adds' => 0, 'cancels' => 0, 'net' => 0, 'billed' => 0, 'collected' => 0]);
            $prevKey = Carbon::createFromFormat('Y-m-d', $key.'-01')->subMonth()->format('Y-m');
            $prev = $mrr->get($prevKey);

            return [
                'key' => $key, 'label' => $m['label'],
                'adds' => $s['adds'], 'cancels' => $s['cancels'], 'net' => $s['net'],
                'clients' => $m['clients'], 'mrr' => $m['mrr'],
                'mrr_growth' => $prev && $prev['mrr'] > 0 ? round(($m['mrr'] - $prev['mrr']) / $prev['mrr'] * 100, 1) : null,
                'collected' => $s['collected'],
            ];
        })->values()->all();
    }

    // ---------------------------------------------------- Análisis de expansión

    /**
     * Cruza prospectos activos con la cobertura de los repetidores. Los prospectos sin
     * cobertura se agrupan (~cluster_km) y cada grupo se evalúa como zona candidata:
     * demanda, solicitudes recientes, MRR potencial, distancia a la red, capacidad
     * disponible cercana y clientes existentes alrededor.
     */
    public function expansion(): array
    {
        $cfg = tenant()->settingsFor('bi');
        $clusterKm = max(0.3, (float) ($cfg['cluster_km'] ?? 1));
        $conversion = max(1, min(100, (float) ($cfg['expected_conversion'] ?? 60))) / 100;
        $repeaterCost = (float) ($cfg['repeater_cost_estimate'] ?? 45000);
        $sectorCost = (float) ($cfg['sector_cost_estimate'] ?? 12000);
        $avgPlan = (float) Plan::query()->where('active', true)->avg('price') ?: 350;

        $repeaters = Repeater::query()->withLoad()->whereNotNull('lat')->get();
        $prospects = Prospect::query()->whereNotIn('status', ['convertido', 'descartado'])
            ->with(['desiredPlan:id,price', 'zone:id,name'])->get();
        $clients = DB::table('clients')->where('tenant_id', $this->tid())->whereNull('deleted_at')
            ->whereIn('service_status', \App\Models\Client::BILLABLE)->whereNotNull('lat')->get(['lat', 'lng']);
        $recentFrom = now()->subDays(30);

        // 1) Cobertura de cada prospecto (geométrica; si no tiene coordenadas, la marcada en el registro)
        $zoneCenters = Zone::query()->whereNotNull('lat')->get(['id', 'name', 'lat', 'lng']);
        $evaluated = $prospects->map(function ($p) use ($repeaters, $zoneCenters) {
            $nearest = null;
            $dist = null;
            if ($p->lat !== null && $p->lng !== null) {
                foreach ($repeaters as $r) {
                    $d = haversine_km((float) $p->lat, (float) $p->lng, (float) $r->lat, (float) $r->lng);
                    if ($dist === null || $d < $dist) {
                        [$dist, $nearest] = [$d, $r];
                    }
                }
            }
            $covered = $nearest ? $dist <= (float) $nearest->coverage_radius_km : (bool) $p->has_coverage;

            $zoneName = $p->zone?->name;
            if ($p->lat !== null && $zoneCenters->isNotEmpty()) {
                $zoneName = $zoneCenters->sortBy(fn ($z) => haversine_km((float) $p->lat, (float) $p->lng, (float) $z->lat, (float) $z->lng))->first()->name;
            }

            return (object) ['p' => $p, 'nearest' => $nearest, 'dist' => $dist, 'covered' => $covered, 'zone' => $zoneName];
        });

        // 2) Demanda por zona (sección 20)
        $byZone = $evaluated->groupBy(fn ($e) => $e->zone ?? 'Sin zona')->map(fn ($g, $name) => [
            'zone' => $name,
            'total' => $g->count(),
            'covered' => $g->where('covered', true)->count(),
            'uncovered' => $g->where('covered', false)->count(),
            'recent' => $g->filter(fn ($e) => $e->p->created_at >= $recentFrom)->count(),
            'potential_mrr' => round($g->sum(fn ($e) => (float) ($e->p->desiredPlan?->price ?? $avgPlan)) * $conversion, 2),
        ])->sortByDesc('total')->values();

        // 3) Agrupación de prospectos sin cobertura (líder-seguidor con radio cluster_km)
        $clusters = [];
        foreach ($evaluated->where('covered', false)->filter(fn ($e) => $e->p->lat !== null) as $e) {
            $lat = (float) $e->p->lat;
            $lng = (float) $e->p->lng;
            $found = null;
            foreach ($clusters as $i => $c) {
                if (haversine_km($lat, $lng, $c['lat'], $c['lng']) <= $clusterKm) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                $clusters[] = ['lat' => $lat, 'lng' => $lng, 'items' => [$e]];
            } else {
                $clusters[$found]['items'][] = $e;
                $n = count($clusters[$found]['items']);
                $clusters[$found]['lat'] += ($lat - $clusters[$found]['lat']) / $n;
                $clusters[$found]['lng'] += ($lng - $clusters[$found]['lng']) / $n;
            }
        }

        // 4) Evaluación de cada zona candidata
        $candidates = collect($clusters)->map(function ($c) use ($repeaters, $clients, $recentFrom, $avgPlan, $conversion, $repeaterCost, $sectorCost, $clusterKm) {
            $items = collect($c['items']);
            $demand = $items->count();
            $avgPrice = round($items->avg(fn ($e) => (float) ($e->p->desiredPlan?->price ?? $avgPlan)), 2);
            $potential = round($demand * $avgPrice * $conversion, 2);

            $near = $repeaters->map(fn ($r) => ['r' => $r, 'd' => haversine_km($c['lat'], $c['lng'], (float) $r->lat, (float) $r->lng)])->sortBy('d')->values();
            $nearest = $near->first();
            $spareNearby = $near->filter(fn ($x) => $x['d'] <= 5 && $x['r']->status !== 'offline')
                ->sum(fn ($x) => max(0, (int) $x['r']->capacity - $x['r']->clientLoad()));
            $existing = $clients->filter(fn ($cl) => haversine_km($c['lat'], $c['lng'], (float) $cl->lat, (float) $cl->lng) <= 1.5)->count();
            $zone = $items->map(fn ($e) => $e->zone)->filter()->countBy()->sortDesc()->keys()->first();

            $canExtend = $nearest && $nearest['d'] <= (float) $nearest['r']->coverage_radius_km * 1.8
                && max(0, (int) $nearest['r']->capacity - $nearest['r']->clientLoad()) >= $demand && $nearest['r']->status !== 'offline';
            $investment = $canExtend ? $sectorCost : $repeaterCost;
            $place = ($zone ? $zone.' ' : '').'('.number_format($c['lat'], 4).', '.number_format($c['lng'], 4).')';

            return [
                'lat' => round($c['lat'], 6), 'lng' => round($c['lng'], 6),
                'zone' => $zone ?? 'Sin zona',
                'demand' => $demand,
                'recent' => $items->filter(fn ($e) => $e->p->created_at >= $recentFrom)->count(),
                'avg_price' => $avgPrice,
                'potential_mrr' => $potential,
                'nearest' => $nearest ? $nearest['r']->code : null,
                'distance_km' => $nearest ? round($nearest['d'], 2) : null,
                'spare_nearby' => $spareNearby,
                'existing_clients' => $existing,
                'type' => $canExtend ? 'extend' : 'new',
                'recommendation' => $canExtend
                    ? "Ampliar cobertura del repetidor {$nearest['r']->code} (nuevo sector/antena) hacia {$place}"
                    : "Instalar nuevo repetidor en {$place}",
                'investment' => $investment,
                'payback_months' => $potential > 0 ? round($investment / $potential, 1) : null,
                'prospects' => $items->map(fn ($e) => $e->p->name)->take(6)->implode(', '),
                'radius_km' => $clusterKm,
            ];
        });

        // 5) Puntaje 0-100
        $maxDemand = max(1, (int) $candidates->max('demand'));
        $maxRecent = max(1, (int) $candidates->max('recent'));
        $maxMrr = max(1, (float) $candidates->max('potential_mrr'));
        $candidates = $candidates->map(function ($c) use ($maxDemand, $maxRecent, $maxMrr) {
            $distanceScore = $c['distance_km'] === null ? 0 : max(0, 1 - $c['distance_km'] / 12);
            $capacityScore = $c['type'] === 'extend' ? 1 : min(1, $c['spare_nearby'] / max(1, $c['demand'] * 4));
            $c['score'] = (int) round(
                $c['demand'] / $maxDemand * 35 + $c['recent'] / $maxRecent * 20 + $c['potential_mrr'] / $maxMrr * 25
                + $distanceScore * 10 + $capacityScore * 10
            );

            return $c;
        })->sortByDesc('score')->values();

        $total = $evaluated->count();
        $covered = $evaluated->where('covered', true)->count();

        return [
            'summary' => [
                'prospects' => $total,
                'covered' => $covered,
                'uncovered' => $total - $covered,
                'recent' => $evaluated->filter(fn ($e) => $e->p->created_at >= $recentFrom)->count(),
                'potential_mrr' => round($evaluated->where('covered', false)->sum(fn ($e) => (float) ($e->p->desiredPlan?->price ?? $avgPlan)) * $conversion, 2),
                'covered_potential_mrr' => round($evaluated->where('covered', true)->sum(fn ($e) => (float) ($e->p->desiredPlan?->price ?? $avgPlan)) * $conversion, 2),
                'clusters' => $candidates->count(),
            ],
            'params' => [
                'cluster_km' => $clusterKm, 'conversion' => $conversion * 100,
                'repeater_cost' => $repeaterCost, 'sector_cost' => $sectorCost, 'avg_plan' => round($avgPlan, 2),
            ],
            'byZone' => $byZone->all(),
            'candidates' => $candidates->all(),
            'map' => $this->expansionMap($evaluated, $candidates),
        ];
    }

    protected function expansionMap(Collection $evaluated, Collection $candidates): array
    {
        $heat = $evaluated->filter(fn ($e) => $e->p->lat !== null)
            ->map(fn ($e) => [(float) $e->p->lat, (float) $e->p->lng, $e->covered ? 0.45 : 1])->values()->all();
        $prospects = $evaluated->filter(fn ($e) => $e->p->lat !== null)->map(fn ($e) => [
            'lat' => (float) $e->p->lat, 'lng' => (float) $e->p->lng, 'type' => 'prospect', 'layer' => 'prospects', 'size' => 18,
            'color' => $e->covered ? '#22A06B' : '#E5484D',
            'popup' => '<b>'.e($e->p->name).'</b><br>'.($e->covered ? 'Con cobertura' : 'Sin cobertura')
                .($e->dist !== null ? ' · '.number_format($e->dist, 2).' km de '.$e->nearest?->code : ''),
        ])->values()->all();
        $markers = array_merge(MapData::repeaters(), $prospects);
        $circles = MapData::coverageCircles();
        foreach ($candidates->take(10) as $i => $c) {
            $markers[] = [
                'lat' => $c['lat'], 'lng' => $c['lng'], 'type' => 'tower', 'layer' => 'Zonas candidatas', 'color' => '#6048D0', 'size' => 34,
                'tooltip' => '#'.($i + 1).' · '.$c['demand'].' interesados', 'permanentTooltip' => $i < 3,
                'popup' => '<b>Candidata #'.($i + 1).' · Puntaje '.$c['score'].'</b><br>'.e($c['recommendation']).'<br>MRR potencial '.money($c['potential_mrr'])
                    .($c['payback_months'] ? '<br>Recuperación estimada '.$c['payback_months'].' meses' : ''),
            ];
            $circles[] = ['lat' => $c['lat'], 'lng' => $c['lng'], 'radius' => $c['radius_km'] * 1000, 'color' => '#6048D0', 'opacity' => 0.15, 'dash' => true, 'layer' => 'Zonas candidatas',
                'popup' => 'Zona candidata #'.($i + 1)];
        }

        return ['markers' => $markers, 'circles' => $circles, 'heat' => $heat];
    }
}
