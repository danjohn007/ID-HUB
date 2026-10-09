<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\ProspectService;
use Illuminate\Support\Collection;

/**
 * Concentración de demanda (secciones 18, 20, 47 y 53 "Expansión"):
 * prospectos abiertos agrupados por zona y por celdas de ~1 km, con cobertura
 * potencial, solicitudes recientes, clientes existentes y capacidad cercana.
 */
class DemandAnalysis
{
    protected ?Collection $prospects = null;

    /** Prospectos abiertos con coordenadas (consulta única y ligera). */
    public function prospects(): Collection
    {
        return $this->prospects ??= Prospect::query()
            ->whereIn('status', ProspectService::OPEN)
            ->get(['id', 'name', 'lat', 'lng', 'zone_id', 'status', 'has_coverage', 'distance_km', 'nearest_repeater_id', 'created_at']);
    }

    protected function summarize(Collection $items): array
    {
        $potentialKm = (float) config('wisp.growth.potential_coverage_km', 4);
        $total = $items->count();
        $covered = $items->where('has_coverage', true)->count();
        $potential = $items->filter(fn ($p) => $p->has_coverage || ($p->distance_km !== null && (float) $p->distance_km <= $potentialKm))->count();
        $recent = $items->filter(fn ($p) => $p->created_at && $p->created_at->gte(now()->subDays(30)))->count();

        return [
            'total' => $total,
            'covered' => $covered,
            'uncovered' => $total - $covered,
            'potential' => $potential,
            'recent' => $recent,
            'coverage_pct' => $total ? (int) round($covered / $total * 100) : 0,
        ];
    }

    /** Agrupado por zona (incluye "Sin zona"). */
    public function byZone(): array
    {
        $zones = Zone::query()->get(['id', 'name', 'color', 'lat', 'lng'])->keyBy('id');

        return $this->prospects()->groupBy(fn ($p) => $p->zone_id ?: 0)
            ->map(function ($items, $zoneId) use ($zones) {
                $z = $zones[$zoneId] ?? null;
                $withCoords = $items->filter(fn ($p) => $p->lat);

                return array_merge($this->summarize($items), [
                    'key' => 'z'.$zoneId,
                    'zone_id' => $zoneId ?: null,
                    'name' => $z?->name ?? 'Sin zona asignada',
                    'color' => $z?->color ?? '#94A3B8',
                    'lat' => $withCoords->count() ? round($withCoords->avg(fn ($p) => (float) $p->lat), 6) : ($z?->lat ? (float) $z->lat : null),
                    'lng' => $withCoords->count() ? round($withCoords->avg(fn ($p) => (float) $p->lng), 6) : ($z?->lng ? (float) $z->lng : null),
                ]);
            })
            ->sortByDesc('total')->values()->all();
    }

    /**
     * Celdas de ~$km km con prospectos. $onlyWithoutZone limita a prospectos sin zona.
     * Cada celda incluye centroide, resumen, repetidor cercano y clientes existentes alrededor.
     */
    public function clusters(?float $km = null, bool $onlyWithoutZone = false, int $min = 2): array
    {
        $km ??= (float) config('wisp.growth.cluster_km', 1);
        $items = $this->prospects()->filter(fn ($p) => $p->lat && $p->lng && (! $onlyWithoutZone || ! $p->zone_id));
        if ($items->isEmpty()) {
            return [];
        }
        $refLat = $items->avg(fn ($p) => (float) $p->lat);
        $dLat = $km / 111.32;
        $dLng = $km / (111.32 * max(0.2, cos(deg2rad($refLat))));
        $zones = Zone::query()->pluck('name', 'id');

        $repeaters = Repeater::query()->withLoad()->whereNotNull('lat')->get();

        return $items->groupBy(fn ($p) => floor((float) $p->lat / $dLat).':'.floor((float) $p->lng / $dLng))
            ->filter(fn ($g) => $g->count() >= $min)
            ->map(function ($g, $key) use ($zones, $repeaters, $km) {
                $lat = round($g->avg(fn ($p) => (float) $p->lat), 6);
                $lng = round($g->avg(fn ($p) => (float) $p->lng), 6);
                $zoneId = $g->pluck('zone_id')->filter()->countBy()->sortDesc()->keys()->first();
                $nearest = $repeaters->map(fn ($r) => ['r' => $r, 'd' => haversine_km($lat, $lng, (float) $r->lat, (float) $r->lng)])->sortBy('d')->first();

                return array_merge($this->summarize($g), [
                    'key' => 'c'.str_replace([':', '-'], ['_', 'm'], $key),
                    'name' => ($zoneId ? $zones[$zoneId] ?? 'Zona' : 'Sin zona').' · celda '.$km.' km',
                    'zone_name' => $zoneId ? ($zones[$zoneId] ?? null) : null,
                    'lat' => $lat,
                    'lng' => $lng,
                    'nearest' => $nearest ? $nearest['r']->code : null,
                    'nearest_km' => $nearest ? round($nearest['d'], 2) : null,
                    'nearest_util' => $nearest ? $nearest['r']->utilization() : null,
                ]);
            })
            ->sortByDesc('total')->values()->all();
    }

    /**
     * Zonas con alta demanda y baja cobertura (candidatas a nuevo repetidor).
     */
    public function hotspots(int $limit = 8): array
    {
        $min = (int) config('wisp.growth.hotspot_min_prospects', 5);
        $maxPct = (int) config('wisp.growth.hotspot_max_coverage_pct', 50);
        $cells = collect($this->clusters(null, false, 2));

        // Fusiona celdas vecinas para obtener zonas de demanda contiguas
        $merged = [];
        foreach ($cells->sortByDesc('uncovered') as $c) {
            foreach ($merged as &$m) {
                if (haversine_km($m['lat'], $m['lng'], $c['lat'], $c['lng']) <= 1.6) {
                    $n = $m['total'] + $c['total'];
                    $m['lat'] = round(($m['lat'] * $m['total'] + $c['lat'] * $c['total']) / max(1, $n), 6);
                    $m['lng'] = round(($m['lng'] * $m['total'] + $c['lng'] * $c['total']) / max(1, $n), 6);
                    foreach (['total', 'covered', 'uncovered', 'potential', 'recent'] as $k) {
                        $m[$k] += $c[$k];
                    }
                    $m['coverage_pct'] = $m['total'] ? (int) round($m['covered'] / $m['total'] * 100) : 0;
                    continue 2;
                }
            }
            unset($m);
            $merged[] = $c;
        }
        unset($m);

        $clients = Client::query()->whereNotNull('lat')->whereIn('service_status', Client::BILLABLE)->get(['lat', 'lng']);

        return collect($merged)
            ->filter(fn ($c) => $c['total'] >= $min && $c['coverage_pct'] <= $maxPct)
            ->map(function ($c) use ($clients) {
                $c['clients_near'] = $clients->filter(fn ($cl) => haversine_km($c['lat'], $c['lng'], (float) $cl->lat, (float) $cl->lng) <= 1.5)->count();
                $c['score'] = $c['uncovered'] * 2 + $c['recent'] + $c['potential'];

                return $c;
            })
            ->sortByDesc('score')->take($limit)->values()->all();
    }

    /** Puntos de calor ponderados para la capa "Concentración de demanda". */
    public function heat(): array
    {
        return $this->prospects()->filter(fn ($p) => $p->lat)
            ->map(fn ($p) => [(float) $p->lat, (float) $p->lng, $p->has_coverage ? 0.5 : 0.9])->values()->all();
    }
}
