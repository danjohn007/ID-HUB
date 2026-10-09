<?php

namespace App\Services\Network;

use App\Models\Client;
use App\Models\Repeater;
use App\Models\Sector;
use App\Models\ServiceEvent;
use App\Services\MikroTik\MikroTikService;
use App\Support\Audit;
use Illuminate\Support\Collection;

/**
 * Balanceo de clientes (sección 13).
 *
 * Detecta repetidores saturados, zonas/sectores con alta utilización y clientes mal distribuidos,
 * y sugiere migraciones hacia repetidores con capacidad disponible cuyo radio de cobertura alcanza
 * al cliente. Solo sugiere: la decisión y la aplicación quedan a cargo del administrador.
 */
class BalancingService
{
    /** Margen bajo el umbral de alerta al que se intenta llevar un repetidor saturado. */
    public const MARGIN = 5;

    public function analyze(): array
    {
        $repeaters = Repeater::query()->withLoad()->with(['zone:id,name', 'tower:id,code'])->orderBy('code')->get();
        $state = $repeaters->mapWithKeys(fn ($r) => [$r->id => [
            'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'status' => $r->status,
            'load' => $r->clientLoad(), 'capacity' => (int) $r->capacity, 'threshold' => (int) $r->alert_threshold,
            'util' => $r->utilization(), 'radius' => (float) $r->coverage_radius_km,
            'lat' => $r->lat !== null ? (float) $r->lat : null, 'lng' => $r->lng !== null ? (float) $r->lng : null,
            'zone' => $r->zone?->name, 'zone_id' => $r->zone_id,
            // Clientes que puede recibir sin llegar a su umbral
            'spare' => (int) floor($r->capacity * $r->alert_threshold / 100) - $r->clientLoad() - 1,
        ]])->all();

        $saturated = collect($state)->filter(fn ($s) => $s['status'] !== 'offline' && $s['util'] >= $s['threshold'])->sortByDesc('util')->values();

        $clients = Client::query()->whereNotIn('service_status', ['cancelado', 'prospecto'])->whereNotNull('lat')->whereNotNull('repeater_id')
            ->with('plan:id,name')->get(['id', 'code', 'first_name', 'last_name', 'lat', 'lng', 'repeater_id', 'service_status', 'plan_id', 'online']);

        // Sugerencias para cada repetidor saturado (asignación codiciosa respetando capacidad del destino)
        $suggestions = [];
        $spare = collect($state)->map(fn ($s) => $s['spare'])->all();
        foreach ($saturated as $s) {
            $target = (int) floor($s['capacity'] * max(0, $s['threshold'] - self::MARGIN) / 100);
            $need = max(1, $s['load'] - $target);
            $options = [];
            foreach ($clients->where('repeater_id', $s['id']) as $c) {
                $dCur = $s['lat'] !== null ? haversine_km((float) $c->lat, (float) $c->lng, $s['lat'], $s['lng']) : null;
                $best = null;
                foreach ($state as $alt) {
                    if ($alt['id'] === $s['id'] || $alt['status'] === 'offline' || $alt['lat'] === null || $alt['util'] >= $alt['threshold']) {
                        continue;
                    }
                    $d = haversine_km((float) $c->lat, (float) $c->lng, $alt['lat'], $alt['lng']);
                    if ($d <= $alt['radius'] && (! $best || $d < $best['distance'])) {
                        $best = ['repeater' => $alt['id'], 'distance' => $d];
                    }
                }
                if ($best) {
                    $options[] = ['client' => $c, 'to' => $best['repeater'], 'distance' => round($best['distance'], 2), 'current' => $dCur !== null ? round($dCur, 2) : null,
                        'delta' => $best['distance'] - ($dCur ?? 0)];
                }
            }
            usort($options, fn ($a, $b) => $a['delta'] <=> $b['delta']);
            $byTarget = [];
            $moved = 0;
            foreach ($options as $o) {
                if ($moved >= $need) {
                    break;
                }
                if (($spare[$o['to']] ?? 0) <= 0) {
                    continue;
                }
                $spare[$o['to']]--;
                $moved++;
                $byTarget[$o['to']][] = $this->clientRow($o['client'], $o['distance'], $o['current']);
            }
            foreach ($byTarget as $to => $rows) {
                $n = count($rows);
                $newUtil = $s['capacity'] ? round(($s['load'] - $n) / $s['capacity'] * 100, 1) : 0;
                $suggestions[] = [
                    'key' => $s['id'].'-'.$to,
                    'from' => $s, 'to' => $state[$to], 'clients' => $rows,
                    'message' => "{$n} ".($n === 1 ? 'cliente podría migrarse' : 'clientes podrían migrarse')." al repetidor {$state[$to]['code']} para reducir la saturación de {$s['code']}",
                    'impact' => "{$s['code']}: {$s['util']}% → {$newUtil}% · {$state[$to]['code']}: {$state[$to]['util']}% → "
                        .round(($state[$to]['load'] + $n) / max(1, $state[$to]['capacity']) * 100, 1).'%',
                ];
            }
            if (! $byTarget) {
                $suggestions[] = ['key' => $s['id'].'-none', 'from' => $s, 'to' => null, 'clients' => [],
                    'message' => "No hay repetidores cercanos con capacidad para recibir clientes de {$s['code']}",
                    'impact' => 'Considera ampliar capacidad o instalar un nuevo repetidor en '.($s['zone'] ?? 'la zona')];
            }
        }

        return [
            'repeaters' => array_values($state),
            'saturated' => $saturated->all(),
            'zones' => $this->zones($state),
            'sectors' => $this->sectors($state),
            'suggestions' => $suggestions,
            'misplaced' => $this->misplaced($clients, $state),
        ];
    }

    protected function clientRow(Client $c, ?float $distance, ?float $current): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'name' => $c->full_name, 'plan' => $c->plan?->name, 'status' => $c->service_status,
            'online' => (bool) $c->online, 'distance' => $distance, 'current' => $current];
    }

    protected function zones(array $state): array
    {
        return collect($state)->groupBy(fn ($s) => $s['zone'] ?? 'Sin zona')->map(function (Collection $rs, $zone) {
            $load = $rs->sum('load');
            $cap = $rs->sum('capacity');

            return ['zone' => $zone, 'repeaters' => $rs->count(), 'load' => $load, 'capacity' => $cap,
                'util' => $cap ? round($load / $cap * 100, 1) : 0, 'saturated' => $rs->filter(fn ($s) => $s['util'] >= $s['threshold'])->count()];
        })->sortByDesc('util')->values()->all();
    }

    protected function sectors(array $state): array
    {
        return Sector::query()->with('tower:id,code')->whereNotNull('repeater_id')->get()->map(function ($s) use ($state) {
            $r = $state[$s->repeater_id] ?? null;

            return $r ? ['name' => $s->name, 'tower' => $s->tower?->code, 'repeater' => $r['code'], 'util' => $r['util'],
                'high' => $r['util'] >= $r['threshold'], 'azimuth' => $s->azimuth, 'frequency' => $s->frequency] : null;
        })->filter()->sortByDesc('util')->values()->all();
    }

    /**
     * Clientes mal distribuidos: fuera del radio de su repetidor, o con otro repetidor con capacidad
     * notablemente más cercano (≥ 40 % y ≥ 0.5 km) que sí lo cubre.
     */
    protected function misplaced(Collection $clients, array $state): array
    {
        $out = [];
        foreach ($clients as $c) {
            $cur = $state[$c->repeater_id] ?? null;
            if (! $cur || $cur['lat'] === null) {
                continue;
            }
            $dCur = haversine_km((float) $c->lat, (float) $c->lng, $cur['lat'], $cur['lng']);
            $best = null;
            foreach ($state as $alt) {
                if ($alt['id'] === $cur['id'] || $alt['lat'] === null || $alt['status'] === 'offline' || $alt['spare'] <= 0) {
                    continue;
                }
                $d = haversine_km((float) $c->lat, (float) $c->lng, $alt['lat'], $alt['lng']);
                if ($d <= $alt['radius'] && (! $best || $d < $best['d'])) {
                    $best = ['id' => $alt['id'], 'd' => $d];
                }
            }
            if (! $best) {
                continue;
            }
            $outside = $dCur > $cur['radius'];
            $muchCloser = $best['d'] <= $dCur * 0.6 && ($dCur - $best['d']) >= 0.5;
            if ($outside || $muchCloser) {
                $out[] = $this->clientRow($c, round($best['d'], 2), round($dCur, 2)) + [
                    'from' => $cur['code'], 'to_id' => $best['id'], 'to' => $state[$best['id']]['code'],
                    'reason' => $outside ? "Fuera del radio de {$cur['code']} ({$cur['radius']} km)" : 'Repetidor con capacidad más cercano',
                    'saving' => round($dCur - $best['d'], 2),
                ];
            }
        }

        return collect($out)->sortByDesc('saving')->take(200)->values()->all();
    }

    /**
     * Migra clientes a otro repetidor. Registra ServiceEvent y auditoría por cliente; si cambia el
     * MikroTik y $applyNetwork, aprovisiona en el nuevo equipo y retira del anterior.
     */
    public function migrate(array $clientIds, Repeater $target, bool $applyNetwork = true, string $reason = 'Balanceo de carga'): array
    {
        $done = 0;
        $errors = 0;
        $target->loadMissing('router');
        foreach (Client::query()->whereIn('id', $clientIds)->with(['repeater:id,code,router_device_id', 'router', 'plan'])->get() as $client) {
            if ((int) $client->repeater_id === (int) $target->id) {
                continue;
            }
            $from = $client->repeater;
            $oldRouter = $client->router ?? $from?->router;
            $newRouter = $target->router ?? $oldRouter;
            $result = 'Cambio administrativo';
            $applied = false;

            if ($applyNetwork && $newRouter && $oldRouter?->id !== $newRouter->id) {
                try {
                    $msg = MikroTikService::for($newRouter)->provisionClient($client);
                    if ($oldRouter) {
                        try {
                            MikroTikService::for($oldRouter)->removeClient($client);
                        } catch (\Throwable) {
                        }
                    }
                    $applied = true;
                    $result = $msg.' en '.$newRouter->name;
                } catch (\Throwable $e) {
                    $errors++;
                    $result = 'Error MikroTik: '.NetworkClient::friendlyError($e);
                }
            } elseif ($newRouter && $oldRouter?->id === $newRouter->id) {
                $result = "Mismo MikroTik ({$newRouter->name}): solo se reasigna el repetidor";
            }

            $client->update([
                'repeater_id' => $target->id,
                'router_device_id' => $target->router_device_id ?: $client->router_device_id,
                'zone_id' => $client->zone_id ?: $target->zone_id,
            ]);
            ServiceEvent::create([
                'tenant_id' => $client->tenant_id, 'client_id' => $client->id, 'type' => 'repeater_change',
                'reason' => mb_substr(($from?->code ?? '—').' → '.$target->code.' · '.$reason, 0, 190), 'source' => 'manual',
                'user_id' => auth()->id(), 'network_applied' => $applied, 'network_result' => mb_substr($result, 0, 190),
                'data' => ['from' => $from?->id, 'to' => $target->id],
            ]);
            Audit::log('migrate', "migró cliente #{$client->code} de ".($from?->code ?? '—')." a {$target->code}", $client,
                ['repeater_id' => $from?->id], ['repeater_id' => $target->id]);
            $done++;
        }

        return ['migrated' => $done, 'errors' => $errors];
    }
}
