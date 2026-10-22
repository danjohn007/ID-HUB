<?php

namespace App\Services\Network;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Services\MikroTik\MikroTikService;
use App\Services\MikroTik\PushTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ping desde el MikroTik hacia los equipos de la red (sección 14): APs, antenas, CPE, switches, UPS y
 * la IP de administración de cada repetidor.
 *
 * Cada destino se asigna al MikroTik de su repetidor / torre / cliente o, si ese no respondió, a
 * cualquier MikroTik del WISP que sí responda. El costo está acotado: máximo N destinos por router y
 * ciclo (count=3, intervalo 200 ms); los repetidores van siempre y el resto rota entre ciclos.
 * En modo push el agente pingea la lista que el servidor le entrega y reporta el resultado.
 */
class PingProbe
{
    public const COUNT = 3;

    public const MAX_TARGETS = 20;

    /** Segundos máximos de ping por router en un ciclo (protege la duración del monitoreo). */
    public const BUDGET_SECONDS = 45;

    /** Prioridad: 0 repetidor, 1 infraestructura, 2 CPE. */
    protected const PRIORITY = ['repeater' => 0, 'cpe' => 2];

    /**
     * @param  Collection<int, NetworkDevice>  $routers
     * @param  array<int, array>  $results  resultado de MonitoringService::pollRouter() por router
     * @return array{device: array<int, array>, repeater: array<int, array>}  [latency, loss, ip, router_id]
     */
    public function run(Collection $routers, array $results, int $max = self::MAX_TARGETS): array
    {
        $out = ['device' => [], 'repeater' => []];
        $plan = $this->plan($routers, $results);

        foreach ($routers as $router) {
            $targets = $plan[$router->id] ?? [];
            if ($router->api_mode === 'push') {
                // El agente pingeará esta lista en su próxima ejecución; se usan los resultados del último reporte.
                $chosen = $this->choose($router->id, $targets, $max);
                Cache::put(PushTransport::targetsKey($router->id), array_keys($chosen), now()->addDay());
                $pings = $this->pushResults(Cache::get(PushTransport::reportKey($router->id), []));
                foreach ($targets as $ip => $t) {
                    if (isset($pings[$ip])) {
                        $this->store($out, $t['subjects'], $pings[$ip] + ['ip' => $ip, 'router_id' => $router->id]);
                    }
                }

                continue;
            }
            if (! $targets) {
                continue;
            }

            $mt = NetworkClient::mikrotik($router);
            $start = microtime(true);
            try {
                foreach ($this->choose($router->id, $targets, $max) as $ip => $t) {
                    if (microtime(true) - $start > static::BUDGET_SECONDS) {
                        break;
                    }
                    try {
                        $r = $mt->ping($ip, static::COUNT);
                    } catch (\Throwable) {
                        continue; // destino inválido o comando rechazado: sin dato este ciclo
                    }
                    if ($r['loss'] === null) {
                        continue;
                    }
                    $this->store($out, $t['subjects'], $r + ['ip' => $ip, 'router_id' => $router->id]);
                }
            } finally {
                try {
                    $mt->transport()->close();
                } catch (\Throwable) {
                }
            }
        }

        return $out;
    }

    protected function store(array &$out, array $subjects, array $r): void
    {
        foreach ($subjects as [$type, $id]) {
            $out[$type][$id] = ['latency' => $r['latency'], 'loss' => $r['loss'], 'ip' => $r['ip'], 'router_id' => $r['router_id']];
        }
    }

    /**
     * Destinos por router: [router_id => [ip => ['priority' => n, 'subjects' => [[tipo, id], ...]]]].
     * Una misma IP (ej. AP = IP de administración del repetidor) se pingea una sola vez.
     */
    public function plan(Collection $routers, array $results): array
    {
        $ok = $routers->filter(fn ($r) => ($results[$r->id]['ok'] ?? false))->keyBy('id');
        if ($ok->isEmpty()) {
            return [];
        }
        // Router de respaldo: cualquiera que responda (preferentemente con conexión directa, no push)
        $fallback = $ok->first(fn ($r) => $r->api_mode !== 'push') ?? $ok->first();
        $pick = fn (?int $id) => $id && $ok->has($id) ? $id : $fallback->id;

        $repeaters = Repeater::query()->get(['id', 'tower_id', 'router_device_id', 'ap_device_id', 'management_ip']);
        $repRouter = $repeaters->pluck('router_device_id', 'id');
        $towerRouter = $repeaters->filter(fn ($r) => $r->router_device_id && $r->tower_id)->pluck('router_device_id', 'tower_id');
        $routerIds = $routers->pluck('id')->flip();

        $plan = [];
        $add = function (int $router, string $ip, int $priority, string $type, int $id) use (&$plan) {
            $plan[$router][$ip] ??= ['priority' => $priority, 'subjects' => []];
            $plan[$router][$ip]['priority'] = min($plan[$router][$ip]['priority'], $priority);
            $plan[$router][$ip]['subjects'][] = [$type, $id];
        };

        foreach ($repeaters as $r) {
            $ip = trim((string) $r->management_ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $add($pick($r->router_device_id ?: ($towerRouter[$r->tower_id] ?? null)), $ip, 0, 'repeater', $r->id);
            }
        }

        $devices = NetworkDevice::query()->where('type', '!=', 'mikrotik')->whereNotIn('status', ['retired', 'in_stock'])
            ->whereNotNull('ip')->where('ip', '!=', '')
            ->get(['id', 'type', 'ip', 'repeater_id', 'tower_id', 'client_id', 'parent_id']);
        $clientIds = $devices->pluck('client_id')->filter()->unique();
        $clients = $clientIds->isEmpty() ? collect() : Client::query()->whereIn('id', $clientIds)->get(['id', 'router_device_id', 'repeater_id'])->keyBy('id');
        $apOf = $repeaters->filter(fn ($r) => $r->ap_device_id)->pluck('id', 'ap_device_id');

        foreach ($devices as $d) {
            $ip = trim((string) $d->ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $c = $d->client_id ? $clients->get($d->client_id) : null;
            $router = ($d->repeater_id ? ($repRouter[$d->repeater_id] ?? null) : null)
                ?? ($d->parent_id && isset($routerIds[$d->parent_id]) ? $d->parent_id : null)
                ?? ($c ? ($c->router_device_id ?: ($repRouter[$c->repeater_id] ?? null)) : null)
                ?? ($d->tower_id ? ($towerRouter[$d->tower_id] ?? null) : null);
            $priority = isset($apOf[$d->id]) ? 0 : (static::PRIORITY[$d->type] ?? 1);
            $add($pick($router), $ip, $priority, 'device', $d->id);
            // El AP del repetidor sirve de IP de administración si el repetidor no tiene una propia
            if (isset($apOf[$d->id]) && ! trim((string) $repeaters->firstWhere('id', $apOf[$d->id])?->management_ip)) {
                $add($pick($router), $ip, 0, 'repeater', $apOf[$d->id]);
            }
        }

        return $plan;
    }

    /**
     * Elige hasta $max destinos: primero los de prioridad 0 (repetidores) y el resto en turno rotativo,
     * para que en redes grandes todos los equipos se midan cada pocos ciclos.
     */
    public function choose(int $routerId, array $targets, int $max): array
    {
        if (count($targets) <= $max) {
            return $targets;
        }
        $first = array_filter($targets, fn ($t) => $t['priority'] === 0);
        $rest = array_diff_key($targets, $first);
        uasort($rest, fn ($a, $b) => $a['priority'] <=> $b['priority']);
        $first = array_slice($first, 0, $max, true);
        $slots = $max - count($first);
        if ($slots <= 0 || ! $rest) {
            return $first;
        }
        $key = "network:ping:rr:{$routerId}";
        $offset = (int) Cache::get($key, 0) % count($rest);
        $rotated = array_slice($rest, $offset, null, true) + array_slice($rest, 0, $offset, true);
        Cache::put($key, ($offset + $slots) % count($rest), now()->addDay());

        return $first + array_slice($rotated, 0, $slots, true);
    }

    /** Resultados de ping a destinos reportados por el agente push: [ip => [latency, loss]]. */
    public function pushResults(?array $report): array
    {
        $raw = $report['ping_targets'] ?? null;
        if (! is_array($raw) || ! $raw) {
            return [];
        }
        $byIp = [];
        if (array_is_list($raw)) {
            foreach ($raw as $row) {
                if (is_array($row) && ! empty($row['host'])) {
                    $byIp[(string) $row['host']][] = $row;
                }
            }
        } else {
            foreach ($raw as $ip => $rows) {
                $rows = is_array($rows) ? $rows : [];
                $byIp[(string) $ip] = array_is_list($rows) ? $rows : [$rows];
            }
        }
        $out = [];
        foreach ($byIp as $ip => $rows) {
            $r = MikroTikService::parsePing($rows, static::COUNT);
            if ($r['loss'] !== null) {
                $out[$ip] = $r;
            }
        }

        return $out;
    }
}
