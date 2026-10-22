<?php

namespace App\Services\MikroTik;

use App\Models\Client;
use App\Models\NetworkDevice;
use Illuminate\Support\Facades\Cache;

/**
 * Simulador de RouterOS para demos y pruebas sin equipos reales. Genera datos
 * coherentes con los clientes registrados en la base de datos.
 */
class SimulatedTransport implements Transport
{
    public function __construct(protected NetworkDevice $device) {}

    protected function clients()
    {
        return Client::withoutGlobalScopes()
            ->where('tenant_id', $this->device->tenant_id)
            ->where('router_device_id', $this->device->id)
            ->whereNotIn('service_status', ['cancelado', 'prospecto'])
            ->get();
    }

    protected function seed(int $salt = 0): int
    {
        return crc32($this->device->id.'-'.$salt.'-'.intdiv(time(), 300));
    }

    public function print(string $path, array $where = []): array
    {
        mt_srand($this->seed(strlen($path)));
        $rows = match ($path) {
            '/system/resource' => [[
                'cpu-load' => (string) mt_rand(4, 68),
                'free-memory' => (string) mt_rand(60, 200) * 1048576,
                'total-memory' => (string) (256 * 1048576),
                'uptime' => mt_rand(1, 40).'d'.mt_rand(0, 23).'h'.mt_rand(0, 59).'m',
                'version' => '7.15.3 (stable)',
                'board-name' => $this->device->model ?: 'CCR2004-1G-12S+2XS',
                'architecture-name' => 'arm64',
            ]],
            '/system/identity' => [['name' => $this->device->name]],
            '/interface' => collect(['ether1-WAN', 'ether2-LAN', 'sfp-sfpplus1', 'wlan1', 'bridge-clientes'])
                ->map(fn ($n, $i) => [
                    '.id' => '*'.($i + 1), 'name' => $n, 'type' => str_starts_with($n, 'wlan') ? 'wlan' : (str_starts_with($n, 'bridge') ? 'bridge' : 'ether'),
                    'running' => 'true', 'disabled' => 'false',
                    'rx-byte' => (string) mt_rand(1e9, 9e11), 'tx-byte' => (string) mt_rand(1e9, 9e11),
                ])->all(),
            '/ppp/secret' => $this->clients()->filter(fn ($c) => $c->pppoe_user)->map(fn ($c) => [
                '.id' => '*'.dechex($c->id), 'name' => $c->pppoe_user, 'service' => 'pppoe',
                'profile' => $c->plan?->mikrotik_profile ?: 'default', 'comment' => $c->code,
                'disabled' => $c->service_status === 'suspendido' ? 'true' : 'false',
            ])->values()->all(),
            '/ppp/active' => $this->clients()->filter(fn ($c) => $c->online && $c->service_status !== 'suspendido')->map(fn ($c) => [
                '.id' => '*'.dechex($c->id), 'name' => $c->pppoe_user ?: $c->code, 'service' => 'pppoe',
                'caller-id' => $c->mac_address, 'address' => $c->ip_address, 'uptime' => mt_rand(1, 20).'d'.mt_rand(0, 23).'h',
            ])->values()->all(),
            '/interface/wireless/registration-table' => $this->clients()->filter(fn ($c) => $c->online)->map(fn ($c) => [
                'mac-address' => $c->mac_address, 'signal-strength' => (string) ($c->signal_dbm ?? -65).'dBm',
                'tx-rate' => mt_rand(54, 300).'Mbps', 'rx-rate' => mt_rand(54, 300).'Mbps', 'uptime' => mt_rand(1, 20).'d',
            ])->values()->all(),
            '/queue/simple' => $this->queueRows(),
            '/ip/dhcp-server/lease' => $this->clients()->filter(fn ($c) => $c->ip_address)->map(fn ($c) => [
                'address' => $c->ip_address, 'mac-address' => $c->mac_address, 'host-name' => 'cpe-'.$c->code, 'status' => $c->online ? 'bound' : 'waiting',
            ])->values()->all(),
            '/ip/hotspot/active' => [],
            '/ip/address' => [
                ['.id' => '*1', 'address' => ($this->device->ip ?: '10.0.0.1').'/24', 'interface' => 'bridge-clientes'],
                ['.id' => '*2', 'address' => '192.168.88.1/24', 'interface' => 'ether2-LAN'],
            ],
            '/ip/firewall/address-list' => $this->clients()->where('service_status', 'suspendido')->filter(fn ($c) => $c->ip_address)->map(fn ($c) => [
                '.id' => '*'.dechex($c->id), 'list' => $this->device->suspended_list ?: 'WISP_MOROSOS', 'address' => $c->ip_address, 'comment' => $c->code,
            ])->values()->all(),
            default => [],
        };
        mt_srand();

        return array_values(array_filter($rows, function ($row) use ($where) {
            foreach ($where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function add(string $path, array $attributes): void
    {
        $this->log('add', $path, $attributes);
    }

    public function update(string $path, array $where, array $attributes): int
    {
        $this->log('set', $path, $attributes, $where);

        return 1;
    }

    public function delete(string $path, array $where): int
    {
        $this->log('remove', $path, [], $where);

        return 1;
    }

    public function command(string $path, array $attributes = []): array
    {
        if ($path === '/ping') {
            return $this->pingRows((string) ($attributes['address'] ?? ''), (int) ($attributes['count'] ?? 3));
        }
        $this->log('command', $path, $attributes);

        return [];
    }

    /**
     * Colas simples "wc-{código}" con contadores de bytes (subida/bajada) que crecen durante el mes
     * a un ritmo estable por cliente (20 a 80 GB/mes), para que el consumo mensual sea verosímil.
     */
    protected function queueRows(): array
    {
        $monthStart = now()->startOfMonth()->getTimestamp();
        $fraction = max(0, time() - $monthStart) / (now()->daysInMonth * 86400);

        return $this->clients()->filter(fn ($c) => $c->ip_address)->loadMissing('plan:id,upload_mbps,download_mbps')->map(function ($c) use ($fraction) {
            $gb = 20 + crc32('q'.$c->id) % 61;
            $down = (int) ($gb * 0.85 * 1073741824 * $fraction);
            $up = (int) ($gb * 0.15 * 1073741824 * $fraction);

            return [
                '.id' => '*'.dechex($c->id), 'name' => 'wc-'.$c->code, 'target' => $c->ip_address.'/32',
                'max-limit' => ((int) round((float) ($c->plan?->upload_mbps ?? 5) * 1000)).'k/'.((int) round((float) ($c->plan?->download_mbps ?? 20) * 1000)).'k',
                'bytes' => $up.'/'.$down, 'comment' => $c->code, 'disabled' => 'false',
            ];
        })->values()->all();
    }

    /** Respuesta simulada de /ping con el formato de la API (acumulados en cada fila). */
    protected function pingRows(string $address, int $count): array
    {
        mt_srand(crc32($this->device->id.'-ping-'.$address.'-'.intdiv(time(), 300)));
        $lost = $this->pingUnreachable($address) ? $count : (mt_rand(1, 100) <= 6 ? 1 : 0);
        $base = mt_rand(100, 600) / 100;
        $rows = [];
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $ok = $i >= $lost;
            $row = ['host' => $address, 'seq' => (string) $i];
            if ($ok) {
                $times[] = round($base + mt_rand(0, 400) / 100, 2);
                $row += ['size' => '56', 'ttl' => '64', 'time' => end($times).'ms'];
            } else {
                $row['status'] = 'timeout';
            }
            $row += ['sent' => (string) ($i + 1), 'received' => (string) count($times), 'packet-loss' => (string) (int) round(($i + 1 - count($times)) / ($i + 1) * 100)];
            if ($times) {
                $row['avg-rtt'] = round(array_sum($times) / count($times), 2).'ms';
            }
            $rows[] = $row;
        }
        mt_srand();

        return $rows;
    }

    /** ¿El destino no responde en la simulación? (lo redefine el simulador de demo). */
    protected function pingUnreachable(string $address): bool
    {
        return false;
    }

    public function close(): void {}

    protected function log(string $op, string $path, array $attrs, array $where = []): void
    {
        $key = "mikrotik:sim:{$this->device->id}:log";
        $log = Cache::get($key, []);
        array_unshift($log, ['at' => now()->toDateTimeString(), 'op' => $op, 'path' => $path, 'attrs' => $attrs, 'where' => $where]);
        Cache::put($key, array_slice($log, 0, 50), now()->addDays(7));
    }
}
