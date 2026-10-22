<?php

namespace App\Services\Network;

use App\Models\Alert;
use App\Models\Client;
use App\Models\ConnectionEvent;
use App\Models\MonitoringSample;
use App\Models\NetworkDevice;
use App\Models\Outage;
use App\Models\Repeater;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Services\AlertService;
use App\Services\AutomationEngine;
use App\Services\MikroTik\MikroTikException;
use App\Services\MikroTik\MikroTikService;
use App\Services\MikroTik\PushTransport;
use App\Services\MikroTik\SimulatedTransport;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Motor de monitoreo de red (secciones 14, 15, 17, 32 y 46).
 *
 * Un ciclo (pollTenant) por WISP:
 *  1. Consulta cada MikroTik (recursos, sesiones activas, tabla de registro) y guarda métricas + MonitoringSample.
 *  2. Actualiza clientes (online, última conexión, señal) y el estado de sus CPE.
 *  3. Evalúa cada repetidor: clientes conectados, saturación, latencia y detección de caídas
 *     (crea Outage, alerta crítica y dispara la automatización "repeater_down"; al recuperarse la cierra).
 *  4. Monitoreo de antenas: señal fuera de rango, CPE sin conexión, desconexiones masivas.
 *  5. Anomalías de sesión para revisión técnica (usuario/MAC no registrados, cambio de CPE, múltiples sesiones).
 *  6. Ping desde el MikroTik (PingProbe) a APs, antenas, CPE, switches, UPS y la IP de administración de cada
 *     repetidor: latencia / pérdida reales por equipo y repetidor, estado en línea de equipos que no son MikroTik
 *     y detección de caídas de repetidores sin MikroTik propio.
 *  7. Consumo mensual por cliente (contadores de cola simple o interfaz PPPoE) y límite de consumo del plan.
 *
 * Las anomalías basadas en datos históricos (IP duplicada, consumo, desconexiones frecuentes) se
 * evalúan en detectClientAnomalies(). Todo es idempotente: las muestras se agrupan en ventanas de 5 min
 * y las alertas se deduplican.
 */
class MonitoringService
{
    /** Estados con servicio vigente (se espera que tengan sesión). */
    public const LIVE = ['activo', 'moroso', 'en_revision'];

    public const SESSION_ANOMALIES = ['anomaly_unauthorized', 'anomaly_mac', 'anomaly_cpe_change', 'anomaly_multi_device'];

    public const HISTORY_ANOMALIES = ['anomaly_duplicate_ip', 'anomaly_usage', 'anomaly_flapping'];

    protected array $settings = [];

    protected Carbon $at;

    protected array $seenKeys = [];

    protected array $summary = [];

    /** Resultados de ping del ciclo: ['device' => [id => [...]], 'repeater' => [id => [...]]]. */
    protected array $pings = ['device' => [], 'repeater' => []];

    /** Nombres de los MikroTik del ciclo (para mensajes). */
    protected array $routerNames = [];

    /** Bytes por GB para el consumo mensual (GiB, como lo reporta RouterOS). */
    public const GB = 1073741824;

    public function __construct(protected AlertService $alerts) {}

    /** Inicio de la ventana de 5 minutos actual (las muestras se agrupan aquí). */
    public static function bucket(?Carbon $time = null): Carbon
    {
        $t = ($time ?? now())->copy()->second(0);

        return $t->minute(intdiv((int) $t->format('i'), 5) * 5);
    }

    public static function peakFactor(?Carbon $time = null): float
    {
        $h = (int) ($time ?? now())->format('H');

        return $h >= 19 && $h <= 23 ? 1.0 : ($h >= 8 ? 0.6 : 0.25);
    }

    // =====================================================================
    // Ciclo principal
    // =====================================================================

    public function pollTenant(Tenant $tenant): array
    {
        return app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $this->settings = $tenant->settingsFor('network');
            $this->at = static::bucket();
            $this->seenKeys = [];
            $this->summary = ['routers_ok' => 0, 'routers_failed' => 0, 'clients_online' => 0, 'went_offline' => 0, 'came_online' => 0,
                'outages_opened' => 0, 'outages_closed' => 0, 'alerts' => 0, 'samples' => 0, 'errors' => []];

            app(MaintenanceService::class)->syncStatuses();

            $routers = NetworkDevice::mikrotik()->whereNotIn('status', ['retired', 'in_stock'])->get();
            $this->routerNames = $routers->pluck('name', 'id')->all();
            $results = [];
            foreach ($routers as $router) {
                $results[$router->id] = $this->pollRouter($router);
            }

            // Ping desde los MikroTik que respondieron (acotado por router y ciclo)
            $this->pings = ['device' => [], 'repeater' => []];
            try {
                $this->pings = app(PingProbe::class)->run($routers, $results, max(1, (int) ($this->settings['ping_max_targets'] ?? PingProbe::MAX_TARGETS)));
            } catch (\Throwable $e) {
                $this->summary['errors'][] = 'Ping: '.mb_substr($e->getMessage(), 0, 160);
            }

            $clients = Client::query()->get(['id', 'code', 'first_name', 'last_name', 'pppoe_user', 'ip_address', 'mac_address', 'router_device_id',
                'repeater_id', 'service_status', 'online', 'signal_dbm', 'cpe_device_id', 'last_online_at', 'zone_id']);
            $repeaters = Repeater::query()->withLoad()->with(['router', 'zone:id,name'])->get();
            $repeaterRouter = $repeaters->pluck('router_device_id', 'id');

            [$connected, $signals] = $this->matchSessions($routers, $results, $clients);

            // Clientes cuyo estado conocemos (su MikroTik respondió o falló)
            $known = collect($results)->filter(fn ($r) => ! $r['never'])->keys()->all();
            $managed = $clients->filter(function ($c) use ($known, $repeaterRouter) {
                $router = $c->router_device_id ?: ($repeaterRouter[$c->repeater_id] ?? null);

                return $router && in_array($router, $known);
            });

            $down = $this->evaluateRepeaters($repeaters, $results, $clients, $connected);
            $this->updateClients($managed, $connected, $signals, $down, $results, $repeaterRouter);
            $this->syncCpeDevices();
            $this->applyDevicePings();
            $this->antennaChecks($repeaters, $down);
            $this->resolveStaleSessionAnomalies($results, $clients, $repeaterRouter);
            $this->accumulateUsage($routers, $results, $clients, $repeaterRouter);
            $this->checkDataCaps();

            $this->summary['clients_online'] = Client::query()->where('online', true)->count();

            return $this->summary;
        });
    }

    /**
     * Sincroniza un solo MikroTik (botón "Sincronizar"): métricas, clientes en línea,
     * última conexión y señal a partir de sesiones activas y tabla de registro.
     */
    public function syncRouter(NetworkDevice $d): array
    {
        return app(TenantContext::class)->run(Tenant::findOrFail($d->tenant_id), function ($tenant) use ($d) {
            $this->settings = $tenant->settingsFor('network');
            $this->at = static::bucket();
            $this->seenKeys = [];
            $this->summary = ['routers_ok' => 0, 'routers_failed' => 0, 'went_offline' => 0, 'came_online' => 0, 'alerts' => 0, 'samples' => 0, 'errors' => []];
            $this->pings = ['device' => [], 'repeater' => []];

            $res = $this->pollRouter($d);
            if (! $res['ok']) {
                return ['ok' => false, 'error' => $res['error']];
            }
            $clients = Client::query()->get(['id', 'code', 'first_name', 'last_name', 'pppoe_user', 'ip_address', 'mac_address', 'router_device_id',
                'repeater_id', 'service_status', 'online', 'signal_dbm', 'cpe_device_id', 'last_online_at', 'zone_id']);
            $repeaterRouter = Repeater::query()->pluck('router_device_id', 'id');
            [$connected, $signals] = $this->matchSessions(collect([$d]), [$d->id => $res], $clients);
            $managed = $clients->filter(fn ($c) => (int) ($c->router_device_id ?: ($repeaterRouter[$c->repeater_id] ?? 0)) === $d->id);
            $this->updateClients($managed, $connected, $signals, [], [$d->id => $res], $repeaterRouter);
            $this->syncCpeDevices();

            return ['ok' => true, 'sessions' => count($res['sessions']), 'matched' => count($connected), 'managed' => $managed->count(),
                'online' => $managed->filter(fn ($c) => isset($connected[$c->id]))->count(),
                'came_online' => $this->summary['came_online'], 'went_offline' => $this->summary['went_offline'], 'signals' => count($signals)];
        });
    }

    /** Consulta un MikroTik, guarda sus métricas y su muestra. */
    public function pollRouter(NetworkDevice $d): array
    {
        $out = ['ok' => false, 'never' => false, 'error' => null, 'sessions' => [], 'registrations' => [], 'queues' => [], 'ifaces' => [],
            'cpu' => null, 'memory' => null, 'latency' => null, 'loss' => null, 'rx' => null, 'tx' => null, 'sim' => false];
        $mt = NetworkClient::mikrotik($d);
        $out['sim'] = $mt->transport() instanceof SimulatedTransport;

        try {
            $report = null;
            if ($d->api_mode === 'push') {
                $report = Cache::get(PushTransport::reportKey($d->id));
                if (! $report) {
                    $out['never'] = true;
                    $out['error'] = 'El agente push aún no ha enviado reportes';
                    $d->forceFill(['status' => 'unknown', 'last_error' => $out['error']])->saveQuietly();

                    return $out;
                }
                $age = isset($report['_received_at']) ? (time() - strtotime($report['_received_at'])) / 60 : 999;
                if ($age > (int) tenant()->setting('network.push_stale_minutes', 15)) {
                    throw new MikroTikException('El agente push no reporta desde hace '.round($age).' min');
                }
            }

            $t0 = microtime(true);
            $res = $mt->resources();
            $rtt = (microtime(true) - $t0) * 1000;
            $out['sessions'] = $mt->activeConnections();
            try {
                $out['registrations'] = $mt->registrations();
            } catch (\Throwable) {
                // Equipos sin paquete wireless
            }
            $ifaces = [];
            if (! $out['sim']) {
                try {
                    $ifaces = $mt->interfaces();
                } catch (\Throwable) {
                }
            }
            $out['ifaces'] = $ifaces;
            try {
                $out['queues'] = $mt->simpleQueues(); // contadores de consumo por cliente
            } catch (\Throwable) {
            }

            $out['ok'] = true;
            $out['cpu'] = $res['cpu'];
            $out['memory'] = $res['memory'];
            $peak = static::peakFactor();
            $n = count($out['sessions']);

            if ($out['sim']) {
                $out['latency'] = round(mt_rand(100, 500) / 100 + $peak * mt_rand(0, 300) / 100, 2);
                $out['loss'] = 0;
                $out['rx'] = (int) ($n * 2.2e6 * $peak * mt_rand(70, 120) / 100);
                $out['tx'] = (int) ($n * 0.4e6 * $peak * mt_rand(70, 120) / 100);
            } else {
                // Latencia / pérdida de la salida a internet medidas por el propio MikroTik (ping al upstream);
                // si el ping no se puede ejecutar queda el tiempo de respuesta de la API, sin dato de pérdida.
                [$out['latency'], $out['loss']] = $this->pushPing($report) ?? $this->upstreamPing($d, $mt) ?? [round($rtt, 2), null];
                [$out['rx'], $out['tx']] = $this->trafficFromCounters($d, $ifaces);
            }

            $warn = ($out['cpu'] !== null && $out['cpu'] >= 90) || ($out['memory'] !== null && $out['memory'] >= 92);
            $d->forceFill([
                'status' => $warn ? 'warning' : 'online',
                'cpu' => $out['cpu'], 'memory' => $out['memory'], 'uptime' => $res['uptime'],
                'latency_ms' => $out['latency'], 'packet_loss' => $out['loss'],
                'firmware' => $res['version'] ? 'RouterOS '.$res['version'] : $d->firmware,
                'last_seen_at' => now(), 'last_sync_at' => now(), 'last_error' => null,
            ])->saveQuietly();
            $this->alerts->resolveFor('device_offline', $d);
            $this->checkQuality($d, $out['latency'], $out['loss']);
            $this->summary['routers_ok']++;
        } catch (\Throwable $e) {
            $out['error'] = NetworkClient::friendlyError($e);
            $wasOnline = $d->status !== 'offline';
            $d->forceFill(['status' => 'offline', 'last_error' => mb_substr($out['error'], 0, 190), 'last_sync_at' => now()])->saveQuietly();
            $this->raise('device_offline', 'critical', "MikroTik {$d->name} sin comunicación", $out['error'], $d, ['router_id' => $d->id]);
            $this->summary['routers_failed']++;
            $this->summary['errors'][] = "{$d->name}: {$out['error']}";
            unset($wasOnline);
        } finally {
            try {
                $mt->transport()->close();
            } catch (\Throwable) {
            }
        }

        $this->sample('device', $d->id, [
            'online' => $out['ok'], 'cpu' => $out['cpu'], 'memory' => $out['memory'],
            'latency_ms' => $out['latency'], 'packet_loss' => $out['ok'] ? $out['loss'] : 100,
            'rx_bps' => $out['rx'], 'tx_bps' => $out['tx'], 'connected_clients' => $out['ok'] ? count($out['sessions']) : 0,
        ]);

        return $out;
    }

    /** Latencia y pérdida reportadas por el agente push (ping a 8.8.8.8), si existen. */
    protected function pushPing(?array $report): ?array
    {
        $pings = $report['ping'] ?? null;
        if (! is_array($pings) || ! $pings) {
            return null;
        }
        $r = MikroTikService::parsePing(array_is_list($pings) ? $pings : [$pings]);

        return $r['loss'] === null ? null : [$r['latency'], $r['loss']];
    }

    /** Ping del MikroTik a su salida a internet (network.ping_upstream, 8.8.8.8 por omisión). */
    protected function upstreamPing(NetworkDevice $d, MikroTikService $mt): ?array
    {
        if ($d->api_mode === 'push') {
            return null;
        }
        try {
            $r = $mt->ping((string) ($this->settings['ping_upstream'] ?? '8.8.8.8'), PingProbe::COUNT);
        } catch (\Throwable) {
            return null;
        }

        return $r['loss'] === null ? null : [$r['latency'], $r['loss']];
    }

    /** bps a partir de contadores rx-byte/tx-byte de la interfaz con más tráfico (WAN). */
    protected function trafficFromCounters(NetworkDevice $d, array $ifaces): array
    {
        $wan = collect($ifaces)->filter(fn ($i) => ! in_array($i['type'] ?? '', ['bridge', 'loopback'], true))
            ->sortByDesc(fn ($i) => (float) ($i['rx-byte'] ?? 0))->first();
        if (! $wan || ! isset($wan['rx-byte'])) {
            return [null, null];
        }
        $key = "network:ifc:{$d->id}";
        $now = ['t' => time(), 'rx' => (float) $wan['rx-byte'], 'tx' => (float) ($wan['tx-byte'] ?? 0), 'name' => $wan['name'] ?? ''];
        $prev = Cache::get($key);
        Cache::put($key, $now, now()->addHours(2));
        if (! $prev || $prev['name'] !== $now['name'] || $now['t'] <= $prev['t'] || $now['rx'] < $prev['rx']) {
            return [null, null];
        }
        $secs = $now['t'] - $prev['t'];

        return [(int) (($now['rx'] - $prev['rx']) * 8 / $secs), (int) (max(0, $now['tx'] - $prev['tx']) * 8 / $secs)];
    }

    // =====================================================================
    // Sesiones → clientes
    // =====================================================================

    public static function mac(?string $mac): string
    {
        return strtoupper(str_replace('-', ':', trim((string) $mac)));
    }

    /**
     * Relaciona sesiones activas y tabla de registro con clientes. Detecta anomalías de sesión.
     *
     * @return array{0: array<int, array>, 1: array<int, float>}  [conectados por cliente, señal por cliente]
     */
    protected function matchSessions(Collection $routers, array $results, Collection $clients): array
    {
        $byUser = $clients->filter(fn ($c) => $c->pppoe_user)->keyBy(fn ($c) => mb_strtolower($c->pppoe_user));
        $byIp = $clients->filter(fn ($c) => $c->ip_address)->keyBy('ip_address');
        $byMac = $clients->filter(fn ($c) => $c->mac_address)->keyBy(fn ($c) => static::mac($c->mac_address));
        $deviceMacs = NetworkDevice::query()->whereNotNull('mac')->pluck('mac')->map(fn ($m) => static::mac($m))->flip();
        $cpeMacByClient = NetworkDevice::query()->whereNotNull('client_id')->whereNotNull('mac')->pluck('mac', 'client_id')->map(fn ($m) => static::mac($m));

        $connected = [];
        $signals = [];
        $count = [];

        foreach ($routers as $router) {
            $res = $results[$router->id] ?? null;
            if (! $res || ! $res['ok']) {
                continue;
            }
            foreach ($res['sessions'] as $s) {
                $user = mb_strtolower((string) ($s['user'] ?? ''));
                $mac = static::mac($s['mac'] ?? '');
                $client = ($user !== '' ? $byUser->get($user) : null)
                    ?? (! empty($s['address']) ? $byIp->get($s['address']) : null)
                    ?? ($mac !== '' ? $byMac->get($mac) : null);

                if (! $client) {
                    if ($user !== '' && $s['type'] !== 'DHCP') {
                        $this->raise('anomaly_unauthorized', 'warning', "Usuario «{$s['user']}» conectado sin cliente registrado",
                            "Sesión {$s['type']} en {$router->name}".($s['address'] ? " · IP {$s['address']}" : '').($mac ? " · MAC {$mac}" : '')
                            .'. Verificar si corresponde a un equipo de pruebas o a un cliente sin registrar. Requiere revisión técnica; no implica uso indebido.',
                            $router, ['router_id' => $router->id, 'user' => $s['user'], 'mac' => $mac, 'ip' => $s['address'] ?? null],
                            "anomaly_unauthorized:dev{$router->id}:".mb_substr($user, 0, 60));
                    } elseif ($mac !== '' && ! isset($deviceMacs[$mac])) {
                        $this->macAnomaly($router, $mac, $s['address'] ?? null);
                    }

                    continue;
                }

                $count[$client->id] = ($count[$client->id] ?? 0) + 1;
                $connected[$client->id] = $s + ['router_id' => $router->id];

                if (in_array($client->service_status, ['suspendido', 'cancelado'], true)) {
                    $label = $client->service_status === 'cancelado' ? 'cancelado' : 'suspendido';
                    $this->raise('anomaly_unauthorized', 'warning', "Sesión activa de cliente {$label}: {$client->full_name}",
                        "El cliente #{$client->code} figura como {$label} pero tiene sesión {$s['type']} en {$router->name}. Verificar que el corte se haya aplicado en el equipo.",
                        $client, ['router_id' => $router->id]);
                }

                $registered = static::mac($client->mac_address);
                $cpeMac = $cpeMacByClient[$client->id] ?? '';
                if ($mac !== '' && $registered !== '' && $mac !== $registered && $mac !== $cpeMac) {
                    $this->raise('anomaly_cpe_change', 'info', "Cambio de CPE detectado: {$client->full_name}",
                        "MAC registrada {$registered} · MAC en sesión {$mac} ({$router->name}). Puede tratarse de un reemplazo de equipo; actualizar el registro tras verificarlo.",
                        $client, ['router_id' => $router->id, 'registered_mac' => $registered, 'session_mac' => $mac]);
                }
            }

            foreach ($res['registrations'] as $r) {
                $mac = static::mac($r['mac-address'] ?? '');
                if ($mac === '') {
                    continue;
                }
                $client = $byMac->get($mac);
                if (! $client) {
                    $owner = $cpeMacByClient->search($mac);
                    $client = $owner ? $clients->firstWhere('id', $owner) : null;
                }
                $dbm = (float) ($r['signal_dbm'] ?? MikroTikService::signalDbm($r));
                if ($client) {
                    if ($dbm) {
                        $signals[$client->id] = $dbm;
                    }
                } elseif (! isset($deviceMacs[$mac])) {
                    $this->macAnomaly($router, $mac, null, $dbm ?: null);
                }
            }
        }

        $byId = $clients->keyBy('id');
        foreach ($count as $clientId => $n) {
            if ($n > 1 && ($c = $byId->get($clientId))) {
                $this->raise('anomaly_multi_device', 'warning', "Múltiples sesiones simultáneas: {$c->full_name}",
                    "El cliente #{$c->code} tiene {$n} sesiones activas al mismo tiempo. Puede indicar varios equipos no esperados; requiere revisión técnica.",
                    $c, ['sessions' => $n]);
            }
        }

        return [$connected, $signals];
    }

    protected function macAnomaly(NetworkDevice $router, string $mac, ?string $ip = null, ?float $signal = null): void
    {
        $this->raise('anomaly_mac', 'info', "MAC no registrada {$mac}",
            "Equipo conectado a {$router->name}".($ip ? " con IP {$ip}" : '').($signal ? " · señal {$signal} dBm" : '')
            .' que no corresponde a ningún cliente ni equipo registrado. Requiere revisión técnica.',
            $router, ['router_id' => $router->id, 'mac' => $mac], "anomaly_mac:dev{$router->id}:{$mac}");
    }

    protected function updateClients(Collection $managed, array $connected, array $signals, array $down, array $results, $repeaterRouter): void
    {
        $now = now();
        $tid = tenant()->id;
        $connectedIds = array_keys($connected);

        foreach (array_chunk($connectedIds, 500) as $chunk) {
            DB::table('clients')->where('tenant_id', $tid)->whereIn('id', $chunk)->update(['online' => true, 'last_online_at' => $now]);
        }
        $this->summary['came_online'] = $managed->filter(fn ($c) => ! $c->online && isset($connected[$c->id]))->count();

        $offline = $managed->filter(fn ($c) => ! isset($connected[$c->id]));
        $wentOffline = $offline->filter(fn ($c) => $c->online);
        foreach ($offline->pluck('id')->chunk(500) as $chunk) {
            DB::table('clients')->where('tenant_id', $tid)->whereIn('id', $chunk->all())->where('online', true)->update(['online' => false]);
        }
        $this->summary['went_offline'] = $wentOffline->count();

        $events = [];
        foreach ($wentOffline as $c) {
            $router = $c->router_device_id ?: ($repeaterRouter[$c->repeater_id] ?? null);
            $routerFailed = $router && isset($results[$router]) && ! $results[$router]['ok'];
            $events[] = [
                'tenant_id' => $tid, 'client_id' => $c->id, 'device_id' => $c->cpe_device_id, 'repeater_id' => $c->repeater_id,
                'type' => in_array((int) $c->repeater_id, $down, true) || $routerFailed ? 'outage' : 'disconnect',
                'detail' => $routerFailed ? 'MikroTik sin comunicación' : null, 'occurred_at' => $now,
            ];
        }
        foreach (array_chunk($events, 300) as $chunk) {
            ConnectionEvent::insert($chunk);
        }

        // Señal (solo cambios relevantes)
        $changes = [];
        $managedById = $managed->keyBy('id');
        foreach ($signals as $id => $dbm) {
            $c = $managedById->get($id);
            if ($c && ($c->signal_dbm === null || abs((float) $c->signal_dbm - $dbm) >= 0.5)) {
                $changes[$id] = $dbm;
            }
        }
        foreach (array_chunk($changes, 300, true) as $chunk) {
            $case = 'CASE id';
            $bindings = [];
            foreach ($chunk as $id => $dbm) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = $dbm;
            }
            $case .= ' ELSE signal_dbm END';
            $ids = implode(',', array_map('intval', array_keys($chunk)));
            DB::update("UPDATE clients SET signal_dbm = {$case} WHERE tenant_id = ? AND id IN ({$ids})", array_merge($bindings, [$tid]));
        }
    }

    /** Estado de los CPE/antenas según el cliente al que están asignados. */
    protected function syncCpeDevices(): void
    {
        $min = (float) ($this->settings['signal_min_dbm'] ?? -75);
        DB::update("UPDATE network_devices d JOIN clients c ON c.cpe_device_id = d.id
            SET d.status = CASE
                    WHEN c.online = 1 AND c.signal_dbm IS NOT NULL AND c.signal_dbm < ? THEN 'warning'
                    WHEN c.online = 1 THEN 'online'
                    WHEN c.service_status IN ('suspendido', 'cancelado') THEN 'unknown'
                    ELSE 'offline' END,
                d.signal_dbm = COALESCE(c.signal_dbm, d.signal_dbm),
                d.last_seen_at = CASE WHEN c.online = 1 THEN ? ELSE d.last_seen_at END
            WHERE d.tenant_id = ? AND d.type IN ('cpe', 'antenna') AND d.status NOT IN ('retired', 'in_stock') AND d.deleted_at IS NULL AND c.deleted_at IS NULL",
            [$min, now(), tenant()->id]);
    }

    // =====================================================================
    // Repetidores: caídas, saturación, calidad
    // =====================================================================

    /** @return int[] ids de repetidores caídos en este ciclo */
    protected function evaluateRepeaters(Collection $repeaters, array $results, Collection $clients, array $connected): array
    {
        $live = $clients->whereIn('service_status', self::LIVE);
        $eligible = $live->countBy('repeater_id');
        // Clientes con servicio vigente cuyo MikroTik respondió: su estado de conexión es confiable aunque
        // el repetidor no tenga MikroTik propio (ej. clientes enrutados por el MikroTik de la torre).
        $okRouters = collect($results)->filter(fn ($r) => $r['ok'])->keys()->flip();
        $repRouter = $repeaters->pluck('router_device_id', 'id');
        $knownBy = $live->filter(fn ($c) => isset($okRouters[(int) ($c->router_device_id ?: ($repRouter[$c->repeater_id] ?? 0))]))->countBy('repeater_id');
        $connectedBy = [];
        $byId = $clients->keyBy('id');
        foreach ($connected as $clientId => $s) {
            $c = $byId->get($clientId);
            if ($c?->repeater_id) {
                $connectedBy[$c->repeater_id] = ($connectedBy[$c->repeater_id] ?? 0) + 1;
            }
        }
        $routerConnected = collect($results)->map(fn ($r) => max(1, count($r['sessions'])));
        $maintenance = app(MaintenanceService::class)->repeatersInMaintenance();
        $down = [];
        $peak = static::peakFactor();

        foreach ($repeaters as $r) {
            $res = $r->router_device_id ? ($results[$r->router_device_id] ?? null) : null;
            $hasRouter = $res && ! $res['never'];
            $ping = $this->pings['repeater'][$r->id] ?? null;
            $elig = (int) ($eligible[$r->id] ?? 0);
            $known = (int) ($knownBy[$r->id] ?? 0);
            $conn = (int) ($connectedBy[$r->id] ?? 0);
            $util = $r->utilization();

            if ($ping) {
                $r->forceFill(['latency_ms' => $ping['latency'], 'packet_loss' => $ping['loss'], 'last_ping_at' => now()])->saveQuietly();
            }
            // Sin MikroTik propio, sin ping a su IP de administración y sin clientes con estado conocido: no hay evidencia
            if (! $hasRouter && ! $ping && $known < 3) {
                $this->checkSaturation($r, $util);

                continue;
            }

            // Caído si: su MikroTik no responde, todos sus clientes (≥ 3) sin sesión, o su IP de administración
            // no responde al ping y no tiene clientes conectados.
            $pingDown = $ping && $ping['loss'] >= 100 && $conn === 0;
            $clientsDown = ($hasRouter ? $elig : $known) >= 3 && $conn === 0;
            $isDown = ($hasRouter && ! $res['ok']) || $clientsDown || $pingDown;
            $inMaintenance = in_array($r->id, $maintenance, true);

            // Métricas del repetidor (simuladas, del ping a su IP de administración o derivadas de su MikroTik)
            if ($hasRouter && $res['sim']) {
                $latency = round(mt_rand(200, 800) / 100 + $peak * mt_rand(200, 1800) / 100 + ($util >= 95 ? mt_rand(7000, 11000) / 100 : 0), 2);
                $loss = round(($peak > 0.9 ? mt_rand(0, 250) : mt_rand(0, 40)) / 100 + ($util >= 95 ? mt_rand(300, 700) / 100 : 0), 2);
                $rx = (int) ($conn * 2.2e6 * $peak * mt_rand(70, 120) / 100);
                $tx = (int) ($conn * 0.4e6 * $peak * mt_rand(70, 120) / 100);
                $cpu = mt_rand(5, 35) + $peak * 20;
                $mem = mt_rand(30, 55);
            } else {
                $latency = $ping && $ping['loss'] < 100 ? $ping['latency'] : ($hasRouter ? $res['latency'] : null);
                $loss = $ping ? $ping['loss'] : ($hasRouter ? $res['loss'] : null);
                $share = $conn / ($routerConnected[$r->router_device_id] ?? 1);
                $rx = $hasRouter && $res['rx'] !== null ? (int) ($res['rx'] * $share) : null;
                $tx = $hasRouter && $res['tx'] !== null ? (int) ($res['tx'] * $share) : null;
                $cpu = null;
                $mem = null;
            }

            if ($isDown) {
                $down[] = $r->id;
                $this->sample('repeater', $r->id, ['online' => false, 'packet_loss' => 100, 'rx_bps' => 0, 'tx_bps' => 0, 'connected_clients' => 0]);
                if ($inMaintenance) {
                    $r->forceFill(['status' => 'maintenance'])->saveQuietly();

                    continue;
                }
                $cause = match (true) {
                    $hasRouter && ! $res['ok'] => "Sin comunicación con {$r->router?->name}",
                    $pingDown => "Sin respuesta a ping de {$ping['ip']}".(($n = $this->routerNames[$ping['router_id']] ?? null) ? " desde {$n}" : '')
                        .($clientsDown ? ' y sin clientes conectados' : ''),
                    default => 'Sin clientes conectados: posible falla del AP o del enlace',
                };
                $this->openOutage($r, $elig, $cause);
                if ($r->ap_device_id) {
                    NetworkDevice::whereKey($r->ap_device_id)->where('status', '!=', 'offline')->update(['status' => 'offline']);
                }

                continue;
            }

            $this->sample('repeater', $r->id, ['online' => true, 'cpu' => $cpu, 'memory' => $mem, 'latency_ms' => $latency, 'packet_loss' => $loss,
                'rx_bps' => $rx, 'tx_bps' => $tx, 'connected_clients' => $conn]);

            if ($r->activeOutage()->exists()) {
                $this->recoverRepeater($r, 'auto');
            }
            if ($r->ap_device_id) {
                NetworkDevice::whereKey($r->ap_device_id)->update(['status' => 'online', 'last_seen_at' => now()]);
            }

            $badQuality = $this->checkQuality($r, $latency, $loss);
            $saturated = $this->checkSaturation($r, $util);
            $status = $inMaintenance ? 'maintenance' : ($saturated || $badQuality ? 'warning' : 'online');
            $r->forceFill(['status' => $status, 'last_seen_at' => now(), 'down_since' => null])->saveQuietly();
        }

        return $down;
    }

    public function openOutage(Repeater $r, int $affected, string $cause): ?Outage
    {
        $outage = Outage::query()->where('repeater_id', $r->id)->whereNull('ended_at')->latest('started_at')->first();
        if ($outage) {
            if ($r->status !== 'offline') {
                $r->forceFill(['status' => 'offline', 'down_since' => $r->down_since ?? $outage->started_at])->saveQuietly();
            }

            return $outage;
        }

        $lastSeen = $r->last_seen_at ?? now();
        $outage = Outage::create([
            'repeater_id' => $r->id,
            'device_id' => $r->ap_device_id ?: $r->router_device_id,
            'zone_id' => $r->zone_id,
            'technician_id' => $r->technician_id,
            'affected_clients' => $affected,
            'started_at' => now(),
            'last_communication_at' => $lastSeen,
            'cause' => mb_substr($cause, 0, 190),
        ]);
        $r->forceFill(['status' => 'offline', 'down_since' => now()])->saveQuietly();
        $this->raise('repeater_down', 'critical', "Repetidor {$r->code} fuera de servicio",
            "{$affected} clientes afectados · {$cause}", $r, ['affected_clients' => $affected, 'outage_id' => $outage->id]);
        app(AutomationEngine::class)->fire('repeater_down', $r, ['affected_clients' => $affected, 'outage' => $outage]);
        $this->summary['outages_opened'] = ($this->summary['outages_opened'] ?? 0) + 1;

        return $outage;
    }

    /** Cierra la falla activa del repetidor (automático al recuperar comunicación o manual). */
    public function recoverRepeater(Repeater $r, string $how = 'auto'): ?Outage
    {
        $outage = Outage::query()->where('repeater_id', $r->id)->whereNull('ended_at')->latest('started_at')->first();
        Cache::forget(DemoRouterTransport::simDownKey($r->id));
        Outage::query()->where('repeater_id', $r->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        $r->forceFill(['status' => 'online', 'down_since' => null, 'last_seen_at' => now()])->saveQuietly();
        $this->alerts->resolveFor('repeater_down', $r);
        if ($r->ap_device_id) {
            NetworkDevice::whereKey($r->ap_device_id)->update(['status' => 'online', 'last_seen_at' => now()]);
        }

        if ($outage) {
            $duration = $outage->started_at->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
            if ($outage->ticket_id && ($ticket = Ticket::find($outage->ticket_id)) && $ticket->isOpen()) {
                $ticket->addComment(($how === 'auto' ? 'El monitoreo detectó que el repetidor '.$r->code.' recuperó la comunicación'
                    : 'Falla del repetidor '.$r->code.' marcada como restablecida por '.(auth()->user()?->name ?? 'el sistema'))
                    .' a las '.now()->format('H:i').'. Duración de la falla: '.$duration.'.', 'comment', true);
            }
            $this->summary['outages_closed'] = ($this->summary['outages_closed'] ?? 0) + 1;
        }

        return $outage;
    }

    /** @return bool true si el repetidor está en o sobre su umbral de saturación */
    protected function checkSaturation(Repeater $r, float $util): bool
    {
        if ($util >= $r->alert_threshold) {
            $this->raise('repeater_saturated', $util >= 100 ? 'critical' : 'warning', "Repetidor {$r->code} saturado",
                "Utilización {$util}% ({$r->clientLoad()} / {$r->capacity} clientes) · umbral {$r->alert_threshold}%", $r,
                ['utilization' => $util]);

            return true;
        }
        $this->alerts->resolveFor('repeater_saturated', $r);

        return false;
    }

    /** Latencia elevada / pérdida de paquetes de un repetidor o equipo. */
    protected function checkQuality($subject, ?float $latency, ?float $loss): bool
    {
        $label = $subject instanceof Repeater ? "repetidor {$subject->code}" : "equipo {$subject->name}";
        $maxLat = (float) ($this->settings['latency_max_ms'] ?? 80);
        $maxLoss = (float) ($this->settings['packet_loss_max'] ?? 5);
        $bad = false;

        if ($latency !== null && $latency > $maxLat) {
            $this->raise('high_latency', 'warning', 'Latencia elevada en '.$label, "Latencia {$latency} ms (máximo {$maxLat} ms)", $subject, ['latency_ms' => $latency]);
            $bad = true;
        } else {
            $this->alerts->resolveFor('high_latency', $subject);
        }
        if ($loss !== null && $loss > $maxLoss) {
            $this->raise('packet_loss', 'warning', 'Pérdida de paquetes en '.$label, "Pérdida {$loss}% (máximo {$maxLoss}%)", $subject, ['packet_loss' => $loss]);
            $bad = true;
        } else {
            $this->alerts->resolveFor('packet_loss', $subject);
        }

        return $bad;
    }

    // =====================================================================
    // Ping a equipos (no MikroTik)
    // =====================================================================

    /**
     * Estado de los equipos con resultado de ping en este ciclo: sustituye al estado copiado del cliente
     * o del repetidor. Guarda latencia / pérdida, muestra de monitoreo y alertas de infraestructura.
     */
    protected function applyDevicePings(): void
    {
        $pings = $this->pings['device'] ?? [];
        if (! $pings) {
            return;
        }
        $maxLat = (float) ($this->settings['latency_max_ms'] ?? 80);
        $maxLoss = (float) ($this->settings['packet_loss_max'] ?? 5);
        $minSignal = (float) ($this->settings['signal_min_dbm'] ?? -75);

        foreach (NetworkDevice::query()->whereIn('id', array_keys($pings))->get() as $d) {
            $p = $pings[$d->id];
            $up = $p['loss'] < 100;
            $degraded = $up && (($p['latency'] !== null && $p['latency'] > $maxLat) || $p['loss'] > $maxLoss);
            $weakSignal = $d->client_id && $d->signal_dbm !== null && (float) $d->signal_dbm < $minSignal;
            $d->forceFill([
                'status' => ! $up ? 'offline' : ($degraded || $weakSignal ? 'warning' : 'online'),
                'latency_ms' => $p['latency'], 'packet_loss' => $p['loss'],
            ] + ($up ? ['last_seen_at' => now()] : []))->saveQuietly();
            $this->sample('device', $d->id, ['online' => $up, 'latency_ms' => $p['latency'], 'packet_loss' => $p['loss']]);

            // CPE de clientes: su falla ya la cubre el monitoreo del cliente (CPE sin conexión)
            if ($d->type === 'cpe' || $d->client_id) {
                continue;
            }
            if ($up) {
                $this->alerts->resolveFor('device_offline', $d);
                $this->checkQuality($d, $p['latency'], $p['loss']);
            } else {
                $from = $this->routerNames[$p['router_id']] ?? null;
                $this->raise('device_offline', in_array($d->type, ['ap', 'switch', 'router', 'ups'], true) ? 'critical' : 'warning',
                    \App\Support\Catalog::label('device_types', $d->type)." {$d->name} sin respuesta",
                    "No responde al ping a {$p['ip']}".($from ? " desde {$from}" : '').'. Revisar energía, cableado o enlace del equipo.',
                    $d, ['ip' => $p['ip'], 'router_id' => $p['router_id']]);
            }
        }
    }

    // =====================================================================
    // Consumo mensual y límite del plan
    // =====================================================================

    /**
     * Acumula el consumo del mes por cliente a partir de los contadores del equipo: cola simple del cliente
     * ("wc-{código}", la dinámica "<pppoe-usuario>", comentario = código o target = IP) o, si no hay cola,
     * la interfaz dinámica PPPoE. Si el contador bajó (reinicio del equipo o reconexión) se suma lo leído.
     * Al cambiar de mes se reinicia el acumulado de los clientes medidos.
     */
    protected function accumulateUsage(Collection $routers, array $results, Collection $clients, $repeaterRouter): void
    {
        $tid = tenant()->id;
        $month = now()->format('Y-m');
        DB::table('clients')->where('tenant_id', $tid)->whereNotNull('usage_month')->where('usage_month', '!=', $month)
            ->update(['month_usage_gb' => 0, 'usage_month' => $month]);

        $counters = [];
        $byRouter = $clients->groupBy(fn ($c) => (int) ($c->router_device_id ?: ($repeaterRouter[$c->repeater_id] ?? 0)));
        foreach ($routers as $router) {
            $res = $results[$router->id] ?? null;
            if (! $res || ! $res['ok'] || (! $res['queues'] && ! $res['ifaces'])) {
                continue;
            }
            $queues = ['name' => [], 'comment' => [], 'target' => []];
            foreach ($res['queues'] as $q) {
                if (! isset($q['bytes']) || ($q['disabled'] ?? 'false') === 'true') {
                    continue;
                }
                $bytes = array_sum(array_map('floatval', explode('/', (string) $q['bytes'])));
                $queues['name'][mb_strtolower((string) ($q['name'] ?? ''))] = $bytes;
                if (! empty($q['comment'])) {
                    $queues['comment'][(string) $q['comment']] = $bytes;
                }
                foreach (explode(',', (string) ($q['target'] ?? '')) as $target) {
                    if (($ip = trim(explode('/', $target)[0])) !== '') {
                        $queues['target'][$ip] = $bytes;
                    }
                }
            }
            $pppoe = [];
            foreach ($res['ifaces'] as $i) {
                if (preg_match('/^<pppoe-(.+)>$/i', (string) ($i['name'] ?? ''), $m) && isset($i['rx-byte'])) {
                    $pppoe[mb_strtolower($m[1])] = (float) $i['rx-byte'] + (float) ($i['tx-byte'] ?? 0);
                }
            }

            foreach ($byRouter->get($router->id, collect()) as $c) {
                $user = mb_strtolower((string) $c->pppoe_user);
                $bytes = $queues['name']['wc-'.mb_strtolower($c->code)]
                    ?? ($user !== '' ? ($queues['name']["<pppoe-{$user}>"] ?? null) : null)
                    ?? $queues['comment'][$c->code]
                    ?? ($c->ip_address ? ($queues['target'][$c->ip_address] ?? null) : null)
                    ?? ($user !== '' ? ($pppoe[$user] ?? null) : null);
                if ($bytes !== null) {
                    $counters[$c->id] = (int) $bytes;
                }
            }
        }
        if (! $counters) {
            return;
        }

        $current = DB::table('clients')->where('tenant_id', $tid)->whereIn('id', array_keys($counters))
            ->get(['id', 'usage_counter', 'usage_month', 'month_usage_gb'])->keyBy('id');
        $rows = [];
        foreach ($counters as $id => $bytes) {
            $c = $current->get($id);
            if (! $c) {
                continue;
            }
            $prev = $c->usage_counter !== null ? (int) $c->usage_counter : null;
            $delta = $prev === null ? 0 : ($bytes >= $prev ? $bytes - $prev : $bytes); // contador reiniciado: se suma lo leído
            if ($delta > 2 * 1099511627776) {
                $delta = 0; // salto imposible en un ciclo (> 2 TB): se toma como nueva línea base
            }
            $rows[$id] = ['usage_counter' => $bytes, 'month_usage_gb' => round((float) $c->month_usage_gb + $delta / self::GB, 2)];
        }
        foreach (array_chunk($rows, 300, true) as $chunk) {
            $counterCase = 'CASE id';
            $usageCase = 'CASE id';
            $bindings = [];
            foreach ($chunk as $id => $v) {
                $counterCase .= ' WHEN '.(int) $id.' THEN ?';
                $bindings[] = $v['usage_counter'];
            }
            foreach ($chunk as $id => $v) {
                $usageCase .= ' WHEN '.(int) $id.' THEN ?';
                $bindings[] = $v['month_usage_gb'];
            }
            $ids = implode(',', array_map('intval', array_keys($chunk)));
            DB::update("UPDATE clients SET usage_counter = {$counterCase} ELSE usage_counter END, month_usage_gb = {$usageCase} ELSE month_usage_gb END, usage_month = ?
                WHERE tenant_id = ? AND id IN ({$ids})", array_merge($bindings, [$month, $tid]));
        }
        $this->summary['usage_measured'] = count($rows);
    }

    /**
     * Clientes que superaron el límite de consumo (data_cap_gb) de su plan: alerta 'data_cap_exceeded'
     * (dispara la automatización del mismo nombre). No se reduce la velocidad salvo que una regla lo haga.
     */
    protected function checkDataCaps(): void
    {
        $over = Client::query()->whereIn('service_status', self::LIVE)
            ->whereHas('plan', fn ($q) => $q->whereNotNull('data_cap_gb')->where('data_cap_gb', '>', 0)->whereColumn('plans.data_cap_gb', '<', 'clients.month_usage_gb'))
            ->with('plan:id,name,data_cap_gb')->get(['id', 'tenant_id', 'code', 'first_name', 'last_name', 'plan_id', 'month_usage_gb']);
        $keys = [];
        foreach ($over as $c) {
            $cap = (int) $c->plan->data_cap_gb;
            $used = (float) $c->month_usage_gb;
            $this->raise('data_cap_exceeded', $used >= $cap * 1.5 ? 'critical' : 'warning', "Límite de consumo superado: {$c->full_name}",
                "{$c->full_name} (#{$c->code}) lleva ".number_format($used, 1)." GB este mes; su plan {$c->plan->name} incluye {$cap} GB ("
                .round($used / $cap * 100).'%).', $c, ['usage_gb' => $used, 'data_cap_gb' => $cap, 'plan_id' => $c->plan_id]);
            $keys[] = 'data_cap_exceeded:'.$c->getMorphClass().':'.$c->id;
        }
        Alert::query()->open()->where('type', 'data_cap_exceeded')->whereNotIn('dedupe_key', $keys ?: ['-'])
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    // =====================================================================
    // Antenas / CPE
    // =====================================================================

    protected function antennaChecks(Collection $repeaters, array $down): void
    {
        $min = (float) ($this->settings['signal_min_dbm'] ?? -75);

        // Señal fuera de rango
        $weak = Client::query()->where('online', true)->whereNotNull('signal_dbm')->where('signal_dbm', '<', $min)
            ->whereIn('service_status', self::LIVE)->get(['id', 'tenant_id', 'code', 'first_name', 'last_name', 'signal_dbm', 'repeater_id']);
        foreach ($weak as $c) {
            $this->raise('abnormal_signal', (float) $c->signal_dbm < $min - 10 ? 'critical' : 'warning', 'Cliente con señal anormal',
                "{$c->full_name} ({$c->code}) · {$c->signal_dbm} dBm (mínimo {$min} dBm)", $c, ['signal_dbm' => (float) $c->signal_dbm]);
        }
        $weakKeys = $weak->map(fn ($c) => 'abnormal_signal:client:'.$c->id)->all();
        Alert::query()->open()->where('type', 'abnormal_signal')->whereNotIn('dedupe_key', $weakKeys ?: ['-'])
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        // CPE sin conexión / desconexión masiva por repetidor (excepto repetidores caídos: ya hay falla)
        $threshold = (int) ($this->settings['mass_disconnect_threshold'] ?? 15);
        $offline = Client::query()->where('online', false)->whereIn('service_status', self::LIVE)->whereNotNull('repeater_id')
            ->get(['id', 'tenant_id', 'code', 'first_name', 'last_name', 'repeater_id', 'last_online_at'])->groupBy('repeater_id');

        foreach ($repeaters as $r) {
            $list = $offline->get($r->id, collect());
            $n = $list->count();
            $isDown = in_array($r->id, $down, true) || $r->status === 'offline';
            $elig = max(1, $r->clientLoad());

            if (! $isDown && $n >= max(3, (int) ceil($elig * 0.05))) {
                $names = $list->take(6)->map(fn ($c) => $c->full_name.' (#'.$c->code.')')->implode(', ');
                $this->raise('device_offline', 'warning', "{$n} CPE sin conexión en {$r->code}",
                    "Clientes con servicio vigente sin sesión activa: {$names}".($n > 6 ? ' y '.($n - 6).' más' : '').'.', $r,
                    ['clients' => $list->pluck('id')->take(100)->values()->all()], "cpe_offline:repeater:{$r->id}");
            } else {
                $this->alerts->resolve("cpe_offline:repeater:{$r->id}");
            }

            if (! $isDown && $n >= $threshold) {
                $this->raise('mass_disconnect', 'critical', "Muchos clientes desconectados en {$r->code}",
                    "{$n} de {$elig} clientes sin conexión. Revisar el AP / enlace del repetidor.", $r, ['offline' => $n]);
            } else {
                $this->alerts->resolveFor('mass_disconnect', $r);
            }
        }
    }

    /** Resuelve anomalías de sesión que ya no se observan en los equipos que respondieron. */
    protected function resolveStaleSessionAnomalies(array $results, Collection $clients, $repeaterRouter): void
    {
        $okRouters = collect($results)->filter(fn ($r) => $r['ok'])->keys()->all();
        $open = Alert::query()->open()->whereIn('type', self::SESSION_ANOMALIES)->get(['id', 'type', 'dedupe_key', 'subject_type', 'subject_id', 'data']);
        $ids = [];
        foreach ($open as $a) {
            if (in_array($a->dedupe_key, $this->seenKeys, true)) {
                continue;
            }
            $router = $a->data['router_id'] ?? null;
            if (! $router && $a->subject_type === 'client' && ($c = $clients->firstWhere('id', $a->subject_id))) {
                $router = $c->router_device_id ?: ($repeaterRouter[$c->repeater_id] ?? null);
            }
            if ($router && in_array((int) $router, $okRouters, true)) {
                $ids[] = $a->id;
            }
        }
        if ($ids) {
            Alert::query()->whereIn('id', $ids)->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    // =====================================================================
    // Clientes con posibles anomalías (análisis histórico, cada hora)
    // =====================================================================

    public function detectClientAnomalies(Tenant $tenant): array
    {
        return app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $this->settings = $tenant->settingsFor('network');
            $this->seenKeys = [];
            $found = ['anomaly_duplicate_ip' => 0, 'anomaly_usage' => 0, 'anomaly_flapping' => 0];

            // IP duplicada
            $dupes = Client::query()->whereNotIn('service_status', ['cancelado', 'prospecto'])->whereNotNull('ip_address')->where('ip_address', '!=', '')
                ->selectRaw('ip_address, COUNT(*) n')->groupBy('ip_address')->havingRaw('COUNT(*) > 1')->pluck('n', 'ip_address');
            foreach ($dupes as $ip => $n) {
                $list = Client::query()->where('ip_address', $ip)->whereNotIn('service_status', ['cancelado', 'prospecto'])->get(['id', 'tenant_id', 'code', 'first_name', 'last_name']);
                $this->raise('anomaly_duplicate_ip', 'warning', "IP duplicada {$ip}",
                    "La IP está asignada a {$n} clientes: ".$list->map(fn ($c) => $c->full_name.' (#'.$c->code.')')->implode(', ').'. Corregir la asignación para evitar conflictos.',
                    $list->first(), ['ip' => $ip, 'clients' => $list->pluck('id')->all()], "anomaly_duplicate_ip:{$ip}");
                $found['anomaly_duplicate_ip']++;
            }

            // Consumo anormal vs. promedio del plan
            $factor = (float) ($this->settings['usage_anomaly_factor'] ?? 3);
            $avg = Client::query()->whereIn('service_status', self::LIVE)->whereNotNull('plan_id')
                ->selectRaw('plan_id, AVG(month_usage_gb) a, COUNT(*) n')->groupBy('plan_id')->get()->filter(fn ($r) => $r->n >= 5 && $r->a > 0)->pluck('a', 'plan_id');
            foreach ($avg as $planId => $a) {
                $heavy = Client::query()->where('plan_id', $planId)->whereIn('service_status', self::LIVE)
                    ->where('month_usage_gb', '>', max(50, $a * $factor))->with('plan:id,name')->get(['id', 'tenant_id', 'code', 'first_name', 'last_name', 'month_usage_gb', 'plan_id']);
                foreach ($heavy as $c) {
                    $x = round($c->month_usage_gb / $a, 1);
                    $this->raise('anomaly_usage', 'info', "Consumo inusual: {$c->full_name}",
                        "Consumo del mes ".number_format($c->month_usage_gb, 1).' GB, '.$x.'× el promedio del plan '.$c->plan?->name.' ('.number_format($a, 1).' GB). Puede deberse a uso legítimo intensivo; revisar si hay equipos adicionales.',
                        $c, ['usage_gb' => (float) $c->month_usage_gb, 'plan_avg_gb' => round($a, 1)]);
                    $found['anomaly_usage']++;
                }
            }

            // Desconexiones frecuentes / problemas recurrentes
            $limit = (int) $tenant->setting('network.flap_threshold', 4);
            $flaps = ConnectionEvent::query()->where('type', 'disconnect')->where('occurred_at', '>=', now()->subDay())
                ->selectRaw('client_id, COUNT(*) n')->groupBy('client_id')->havingRaw('COUNT(*) >= ?', [$limit])->pluck('n', 'client_id');
            $week = ConnectionEvent::query()->where('type', 'disconnect')->where('occurred_at', '>=', now()->subDays(7))
                ->selectRaw('client_id, COUNT(*) n')->groupBy('client_id')->havingRaw('COUNT(*) >= ?', [$limit * 3])->pluck('n', 'client_id');
            $ids = $flaps->keys()->merge($week->keys())->unique();
            foreach (Client::query()->whereIn('id', $ids)->get(['id', 'tenant_id', 'code', 'first_name', 'last_name', 'signal_dbm']) as $c) {
                $d = (int) ($flaps[$c->id] ?? 0);
                $w = (int) ($week[$c->id] ?? 0);
                $this->raise('anomaly_flapping', 'warning', "Desconexiones frecuentes: {$c->full_name}",
                    ($d ? "{$d} desconexiones en 24 h" : '').($d && $w ? ' · ' : '').($w ? "{$w} en 7 días (problema recurrente)" : '')
                    .($c->signal_dbm ? " · señal {$c->signal_dbm} dBm" : '').'. Revisar alineación de antena, cableado y alimentación del CPE.',
                    $c, ['disconnects_24h' => $d, 'disconnects_7d' => $w]);
                $found['anomaly_flapping']++;
            }

            // Resolver las que ya no se cumplen
            Alert::query()->open()->whereIn('type', self::HISTORY_ANOMALIES)->whereNotIn('dedupe_key', $this->seenKeys ?: ['-'])
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            return $found;
        });
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    protected function raise(string $type, string $severity, string $title, string $message, $subject, array $data = [], ?string $key = null): void
    {
        $key ??= $type.':'.$subject->getMorphClass().':'.$subject->getKey();
        $this->seenKeys[] = $key;
        $exists = Alert::query()->open()->where('dedupe_key', $key)->exists();
        $this->alerts->raise($type, $severity, mb_substr($title, 0, 190), $message, $subject, $data, $key);
        if (! $exists) {
            $this->summary['alerts'] = ($this->summary['alerts'] ?? 0) + 1;
        }
    }

    protected function sample(string $type, int $id, array $values): void
    {
        MonitoringSample::query()->updateOrCreate(
            ['subject_type' => $type, 'subject_id' => $id, 'sampled_at' => $this->at],
            $values + ['tenant_id' => tenant()->id],
        );
        $this->summary['samples'] = ($this->summary['samples'] ?? 0) + 1;
    }

    /** Elimina muestras y eventos de conexión antiguos (todos los WISP). */
    public function prune(int $days = 30): array
    {
        $cut = now()->subDays($days);
        $samples = 0;
        do {
            $n = DB::table('monitoring_samples')->where('sampled_at', '<', $cut)->limit(5000)->delete();
            $samples += $n;
        } while ($n > 0);
        $events = DB::table('connection_events')->where('occurred_at', '<', $cut)->delete();

        return ['samples' => $samples, 'events' => $events];
    }
}
