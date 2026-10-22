<?php

namespace App\Services\MikroTik;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * Operaciones de alto nivel sobre un MikroTik: consulta de estado y
 * aprovisionamiento / corte / reconexión / cambio de velocidad de clientes.
 *
 *   $mt = MikroTikService::for($device);
 *   $mt->resources();  $mt->suspendClient($client);
 */
class MikroTikService
{
    protected ?Transport $transport = null;

    public function __construct(protected NetworkDevice $device) {}

    public static function for(NetworkDevice $device): static
    {
        return new static($device);
    }

    public function device(): NetworkDevice
    {
        return $this->device;
    }

    public function transport(): Transport
    {
        if ($this->transport) {
            return $this->transport;
        }
        $d = $this->device;

        return $this->transport = match ($d->api_mode) {
            'api' => new ApiTransport($d->api_host ?: $d->ip, (int) ($d->api_port ?: ($d->api_ssl ? 8729 : 8728)), (string) $d->api_user, (string) $d->api_password, (bool) $d->api_ssl),
            'rest' => new RestTransport($d->api_host ?: $d->ip, (int) ($d->api_port ?: ($d->api_ssl ? 443 : 80)), (string) $d->api_user, (string) $d->api_password, (bool) $d->api_ssl),
            'push' => new PushTransport($d),
            default => new SimulatedTransport($d),
        };
    }

    public function setTransport(Transport $transport): static
    {
        $this->transport = $transport;

        return $this;
    }

    public function isDeferred(): bool
    {
        return $this->device->api_mode === 'push';
    }

    // ------------------------------------------------------------------ Consultas

    /** Prueba de conexión: identidad, versión y modelo. */
    public function test(): array
    {
        $res = $this->transport()->print('/system/resource')[0] ?? [];
        $identity = $this->transport()->print('/system/identity')[0]['name'] ?? $this->device->name;

        return [
            'identity' => $identity,
            'version' => $res['version'] ?? '—',
            'board' => $res['board-name'] ?? '—',
            'uptime' => $res['uptime'] ?? '—',
        ];
    }

    /** CPU %, memoria % usada, uptime, versión. */
    public function resources(): array
    {
        $r = $this->transport()->print('/system/resource')[0] ?? [];
        $total = (float) ($r['total-memory'] ?? 0);
        $free = (float) ($r['free-memory'] ?? 0);

        return [
            'cpu' => isset($r['cpu-load']) ? (float) $r['cpu-load'] : null,
            'memory' => $total > 0 ? round(($total - $free) / $total * 100, 1) : null,
            'uptime' => $r['uptime'] ?? null,
            'version' => $r['version'] ?? null,
            'board' => $r['board-name'] ?? null,
        ];
    }

    public function interfaces(): array
    {
        return $this->transport()->print('/interface');
    }

    public function pppActive(): array
    {
        return $this->transport()->print('/ppp/active');
    }

    public function pppSecrets(): array
    {
        return $this->transport()->print('/ppp/secret');
    }

    public function hotspotActive(): array
    {
        return $this->transport()->print('/ip/hotspot/active');
    }

    public function dhcpLeases(): array
    {
        return $this->transport()->print('/ip/dhcp-server/lease');
    }

    public function ipAddresses(): array
    {
        return $this->transport()->print('/ip/address');
    }

    /** Menús de tabla de registro: wireless (v6 / v7 legacy), wifi (v7.13+) y wifiwave2 (v7 previo a 7.13). */
    public const REGISTRATION_MENUS = ['/interface/wireless/registration-table', '/interface/wifi/registration-table', '/interface/wifiwave2/registration-table'];

    /**
     * Tabla de registro inalámbrica consolidada de los tres paquetes de RouterOS. Cada fila trae
     * 'signal_dbm' (float) y 'signal-strength' normalizado a "-63dBm". Los menús que el equipo no
     * tiene se omiten (y se recuerdan unas horas para no consultarlos en cada ciclo).
     */
    public function registrations(): array
    {
        $out = [];
        $failed = 0;
        foreach (self::REGISTRATION_MENUS as $menu) {
            $skip = 'mikrotik:nomenu:'.$this->device->id.':'.md5($menu);
            if (Cache::has($skip)) {
                $failed++;

                continue;
            }
            try {
                $rows = $this->transport()->print($menu);
            } catch (MikroTikException $e) {
                // "no such command": el paquete no está instalado en este equipo (otros errores no se recuerdan)
                if (preg_match('/no such|unknown|not found|bad request|REST 40[04]/i', $e->getMessage())) {
                    Cache::put($skip, true, now()->addHours(6));
                }
                $failed++;

                continue;
            }
            foreach ($rows as $r) {
                $out[] = static::normalizeRegistration($r, $menu);
            }
        }
        if ($failed === count(self::REGISTRATION_MENUS)) {
            throw new MikroTikException('El equipo no tiene tabla de registro inalámbrica');
        }

        return $out;
    }

    /** Normaliza una fila de registration-table (wireless / wifi / wifiwave2). */
    public static function normalizeRegistration(array $r, string $menu = ''): array
    {
        $dbm = static::signalDbm($r);
        $r['signal_dbm'] = $dbm;
        if ($dbm !== null) {
            $r['signal-strength'] = $dbm.'dBm';
        }
        $r['_menu'] = $menu;

        return $r;
    }

    /**
     * Señal en dBm de una fila de registro. wireless: "signal-strength" = "-63dBm@6Mbps" o "-63dBm@HT20-7";
     * wifi / wifiwave2 (v7): "signal" = "-63". Se toma el primer número con signo.
     */
    public static function signalDbm(array $r): ?float
    {
        foreach (['signal-strength', 'signal', 'signal-strength-ch0', 'rx-signal'] as $k) {
            if (isset($r[$k]) && preg_match('/-?\d+(?:\.\d+)?/', (string) $r[$k], $m)) {
                $v = (float) $m[0];
                if ($v < 0 && $v > -120) {
                    return $v;
                }
            }
        }

        return null;
    }

    public function simpleQueues(): array
    {
        return $this->transport()->print('/queue/simple');
    }

    /**
     * Ping ejecutado POR EL MIKROTIK hacia $address (/ping address=X count=N).
     *
     * @return array{latency: ?float, loss: ?float, sent: int, received: int}
     */
    public function ping(string $address, int $count = 3): array
    {
        if ($this->isDeferred()) {
            throw new MikroTikException('En modo push el ping lo ejecuta el agente del equipo');
        }
        $count = max(1, min(10, $count));
        $rows = $this->transport()->command('/ping', ['address' => $address, 'count' => $count, 'interval' => '200ms']);

        return static::parsePing($rows, $count);
    }

    /**
     * Interpreta la salida de /ping (API, REST o "as-value" del agente push).
     * Usa los acumulados (sent / received / avg-rtt) cuando existen; si no, cuenta respuestas y timeouts.
     *
     * @return array{latency: ?float, loss: ?float, sent: int, received: int}
     */
    public static function parsePing(array $rows, int $expected = 0): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && ! in_array($r['!type'] ?? '!re', ['!done', '!trap', '!fatal'], true)));
        $last = $rows ? end($rows) : [];
        if (isset($last['sent'], $last['received']) && (int) $last['sent'] > 0) {
            $sent = (int) $last['sent'];
            $received = min($sent, (int) $last['received']);
            $avg = isset($last['avg-rtt']) ? static::durationMs((string) $last['avg-rtt']) : null;
            if ($avg === null && $received > 0) {
                $times = array_filter(array_map(fn ($r) => isset($r['time']) ? static::durationMs((string) $r['time']) : null, $rows), fn ($t) => $t !== null);
                $avg = $times ? array_sum($times) / count($times) : null;
            }

            return ['latency' => $received > 0 && $avg !== null ? round($avg, 2) : null, 'loss' => round(($sent - $received) / $sent * 100, 2), 'sent' => $sent, 'received' => $received];
        }

        $times = [];
        foreach ($rows as $r) {
            $t = isset($r['time']) && ($r['status'] ?? '') === '' ? static::durationMs((string) $r['time']) : null;
            if ($t !== null) {
                $times[] = $t;
            }
        }
        // Sin filas no hay dato (no se asume pérdida); con filas, las que falten frente a "count" se cuentan perdidas
        $sent = $rows ? max(count($rows), $expected) : 0;
        if ($sent === 0) {
            return ['latency' => null, 'loss' => null, 'sent' => 0, 'received' => 0];
        }
        $received = min($sent, count($times));

        return ['latency' => $times ? round(array_sum($times) / count($times), 2) : null, 'loss' => round(($sent - $received) / $sent * 100, 2), 'sent' => $sent, 'received' => $received];
    }

    /** Duración de RouterOS a milisegundos: "12ms345us", "1ms", "850us", "1s200ms", "00:00:00.012345". */
    public static function durationMs(string $t): ?float
    {
        $t = trim($t);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $t, $m)) {
            return ((int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3]) * 1000;
        }
        if (is_numeric($t)) {
            return (float) $t;
        }
        if (! preg_match_all('/(\d+(?:\.\d+)?)(ms|us|s|m|h)/', $t, $parts, PREG_SET_ORDER)) {
            return null;
        }
        $ms = 0.0;
        foreach ($parts as [, $n, $unit]) {
            $ms += (float) $n * ['h' => 3600000, 'm' => 60000, 's' => 1000, 'ms' => 1, 'us' => 0.001][$unit];
        }

        return $ms;
    }

    public function suspendedList(): array
    {
        return $this->transport()->print('/ip/firewall/address-list', ['list' => $this->listName()]);
    }

    /** Conexiones activas consolidadas (PPPoE + Hotspot + DHCP bound). */
    public function activeConnections(): array
    {
        $out = [];
        foreach ($this->pppActive() as $r) {
            $out[] = ['type' => 'PPPoE', 'user' => $r['name'] ?? '', 'address' => $r['address'] ?? '', 'mac' => $r['caller-id'] ?? '', 'uptime' => $r['uptime'] ?? ''];
        }
        foreach ($this->hotspotActive() as $r) {
            $out[] = ['type' => 'Hotspot', 'user' => $r['user'] ?? '', 'address' => $r['address'] ?? '', 'mac' => $r['mac-address'] ?? '', 'uptime' => $r['uptime'] ?? ''];
        }
        if ($this->device->service_method === 'dhcp') {
            foreach ($this->dhcpLeases() as $r) {
                if (($r['status'] ?? '') === 'bound') {
                    $out[] = ['type' => 'DHCP', 'user' => $r['host-name'] ?? '', 'address' => $r['address'] ?? '', 'mac' => $r['mac-address'] ?? '', 'uptime' => ''];
                }
            }
        }

        return $out;
    }

    /** Estado de conexión de un cliente: online, ip, mac, uptime, señal. */
    public function clientConnection(Client $client): array
    {
        $session = null;
        foreach ($this->activeConnections() as $c) {
            if (($client->pppoe_user && $c['user'] === $client->pppoe_user)
                || ($client->ip_address && $c['address'] === $client->ip_address)
                || ($client->mac_address && strcasecmp($c['mac'], $client->mac_address) === 0)) {
                $session = $c;
                break;
            }
        }
        $signal = null;
        if ($client->mac_address) {
            try {
                $regs = $this->registrations();
            } catch (MikroTikException) {
                $regs = []; // equipo sin paquete inalámbrico
            }
            foreach ($regs as $r) {
                if (strcasecmp($r['mac-address'] ?? '', $client->mac_address) === 0) {
                    $signal = $r['signal_dbm'];
                    break;
                }
            }
        }

        return [
            'online' => $session !== null,
            'address' => $session['address'] ?? null,
            'mac' => $session['mac'] ?? null,
            'uptime' => $session['uptime'] ?? null,
            'type' => $session['type'] ?? null,
            'signal' => $signal,
        ];
    }

    // ------------------------------------------------------------------ Automatizaciones

    /** Crea o actualiza el usuario del cliente según el método de servicio. */
    public function provisionClient(Client $client): string
    {
        $t = $this->transport();
        $method = $this->method();

        if ($method === 'pppoe') {
            $user = $client->pppoe_user ?: $client->code;
            $attrs = ['password' => (string) ($client->pppoe_password ?: $client->code), 'service' => 'pppoe', 'profile' => $this->profileFor($client->plan), 'comment' => $client->code];
            if ($t->print('/ppp/secret', ['name' => $user]) || $this->isDeferred()) {
                $this->isDeferred()
                    ? $t->add('/ppp/secret', ['name' => $user] + $attrs)
                    : $t->update('/ppp/secret', ['name' => $user], $attrs);
            } else {
                $t->add('/ppp/secret', ['name' => $user] + $attrs);
            }
        } elseif ($method === 'hotspot') {
            $user = $client->pppoe_user ?: $client->code;
            $t->add('/ip/hotspot/user', ['name' => $user, 'password' => (string) ($client->pppoe_password ?: $client->code), 'comment' => $client->code]);
        } elseif ($method === 'dhcp' && $client->ip_address && $client->mac_address) {
            $t->add('/ip/dhcp-server/lease', ['address' => $client->ip_address, 'mac-address' => $client->mac_address, 'comment' => $client->code]);
        }

        if (! $client->plan?->mikrotik_profile || $method !== 'pppoe') {
            $this->applyQueue($client);
        }

        return "Cliente {$client->code} aprovisionado ({$method})";
    }

    /**
     * Suspende el servicio. total: deshabilita usuario y corta sesión.
     * partial: agrega la IP a la address-list de morosos (el firewall redirige al portal de pago).
     */
    public function suspendClient(Client $client, string $blockType = 'total'): string
    {
        $t = $this->transport();
        $method = $this->method();

        if ($blockType === 'partial' && $client->ip_address) {
            $t->add('/ip/firewall/address-list', ['list' => $this->listName(), 'address' => $client->ip_address, 'comment' => $client->code]);

            return "IP {$client->ip_address} agregada a {$this->listName()} (bloqueo parcial)";
        }

        if ($method === 'pppoe' && ($user = $client->pppoe_user ?: $client->code)) {
            $t->update('/ppp/secret', ['name' => $user], ['disabled' => true]);
            $t->delete('/ppp/active', ['name' => $user]);

            return "Usuario PPPoE {$user} deshabilitado y sesión cerrada";
        }
        if ($method === 'hotspot') {
            $user = $client->pppoe_user ?: $client->code;
            $t->update('/ip/hotspot/user', ['name' => $user], ['disabled' => true]);
            $t->delete('/ip/hotspot/active', ['user' => $user]);

            return "Usuario hotspot {$user} deshabilitado";
        }
        if ($client->ip_address) {
            $t->add('/ip/firewall/address-list', ['list' => $this->listName(), 'address' => $client->ip_address, 'comment' => $client->code]);

            return "IP {$client->ip_address} bloqueada en {$this->listName()}";
        }

        throw new MikroTikException('El cliente no tiene usuario PPPoE ni IP para aplicar el corte.');
    }

    public function reactivateClient(Client $client): string
    {
        $t = $this->transport();
        $method = $this->method();
        $done = [];

        if ($client->ip_address) {
            $t->delete('/ip/firewall/address-list', ['list' => $this->listName(), 'address' => $client->ip_address]);
            $done[] = 'IP retirada de '.$this->listName();
        }
        if ($method === 'pppoe') {
            $user = $client->pppoe_user ?: $client->code;
            $t->update('/ppp/secret', ['name' => $user], ['disabled' => false]);
            $done[] = "PPPoE {$user} habilitado";
        } elseif ($method === 'hotspot') {
            $user = $client->pppoe_user ?: $client->code;
            $t->update('/ip/hotspot/user', ['name' => $user], ['disabled' => false]);
            $done[] = "Hotspot {$user} habilitado";
        }

        return implode('; ', $done) ?: 'Sin cambios';
    }

    /** Cambia velocidad / plan del cliente en el equipo. */
    public function applyPlan(Client $client, ?Plan $plan = null): string
    {
        $plan ??= $client->plan;
        if ($this->method() === 'pppoe' && $plan?->mikrotik_profile) {
            $user = $client->pppoe_user ?: $client->code;
            $this->transport()->update('/ppp/secret', ['name' => $user], ['profile' => $plan->mikrotik_profile]);
            $this->transport()->delete('/ppp/active', ['name' => $user]); // reconecta con el nuevo perfil

            return "Perfil {$plan->mikrotik_profile} aplicado a {$user}";
        }

        return $this->applyQueue($client, $plan);
    }

    /** Agrega la IP del cliente a una address-list (sin duplicar si el equipo permite consultarla). */
    public function addToAddressList(Client $client, string $list, ?string $comment = null): string
    {
        if (! $client->ip_address) {
            throw new MikroTikException('El cliente no tiene IP para agregarla a la address-list.');
        }
        $t = $this->transport();
        if (! $this->isDeferred() && $t->print('/ip/firewall/address-list', ['list' => $list, 'address' => $client->ip_address])) {
            return "IP {$client->ip_address} ya estaba en {$list}";
        }
        $t->add('/ip/firewall/address-list', ['list' => $list, 'address' => $client->ip_address, 'comment' => $comment ?: $client->code]);

        return "IP {$client->ip_address} agregada a {$list}";
    }

    public function removeFromAddressList(Client $client, string $list): string
    {
        if (! $client->ip_address) {
            throw new MikroTikException('El cliente no tiene IP para retirarla de la address-list.');
        }
        $n = $this->transport()->delete('/ip/firewall/address-list', ['list' => $list, 'address' => $client->ip_address]);

        return $n === 0 ? "IP {$client->ip_address} no estaba en {$list}" : "IP {$client->ip_address} retirada de {$list}";
    }

    public function removeClient(Client $client): void
    {
        $t = $this->transport();
        $user = $client->pppoe_user ?: $client->code;
        $t->delete('/ppp/secret', ['name' => $user]);
        $t->delete('/queue/simple', ['name' => 'wc-'.$client->code]);
        if ($client->ip_address) {
            $t->delete('/ip/firewall/address-list', ['list' => $this->listName(), 'address' => $client->ip_address]);
        }
    }

    protected function applyQueue(Client $client, ?Plan $plan = null): string
    {
        $plan ??= $client->plan;
        if (! $plan || ! $client->ip_address) {
            return 'Sin cola: falta plan o IP';
        }
        $limit = $this->mbps($plan->upload_mbps).'/'.$this->mbps($plan->download_mbps);
        $name = 'wc-'.$client->code;
        $t = $this->transport();
        if (! $this->isDeferred() && $t->print('/queue/simple', ['name' => $name])) {
            $t->update('/queue/simple', ['name' => $name], ['max-limit' => $limit, 'target' => $client->ip_address.'/32']);
        } else {
            if ($this->isDeferred()) {
                $t->delete('/queue/simple', ['name' => $name]);
            }
            $t->add('/queue/simple', ['name' => $name, 'target' => $client->ip_address.'/32', 'max-limit' => $limit, 'comment' => $client->code]);
        }

        return "Cola {$name} = {$limit}";
    }

    protected function mbps(float|string $v): string
    {
        return ((int) round((float) $v * 1000)).'k';
    }

    protected function method(): string
    {
        return $this->device->service_method ?: 'pppoe';
    }

    protected function listName(): string
    {
        return $this->device->suspended_list ?: 'WISP_MOROSOS';
    }

    protected function profileFor(?Plan $plan): string
    {
        return $plan?->mikrotik_profile ?: 'default';
    }
}
