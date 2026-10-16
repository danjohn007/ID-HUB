<?php

namespace App\Services\MikroTik;

use App\Models\NetworkDevice;
use Illuminate\Support\Facades\Cache;

/**
 * Modo "push" para servidores que no pueden abrir conexiones salientes al MikroTik
 * (típico en hosting compartido / cPanel). El MikroTik ejecuta un script programado que:
 *  1) envía telemetría a  POST /mikrotik/push/{token}/report
 *  2) descarga comandos de GET  /mikrotik/push/{token}/commands  (script .rsc) y los importa.
 * Las escrituras se encolan como líneas de script; las lecturas devuelven la última telemetría.
 */
class PushTransport implements Transport
{
    public function __construct(protected NetworkDevice $device) {}

    public static function queueKey(int $deviceId): string
    {
        return "mikrotik:push:{$deviceId}:queue";
    }

    public static function reportKey(int $deviceId): string
    {
        return "mikrotik:push:{$deviceId}:report";
    }

    /** Mapeo de menú -> clave en el reporte enviado por el script. */
    protected const REPORT_PATHS = [
        '/system/resource' => 'resource',
        '/system/identity' => 'identity',
        '/interface' => 'interfaces',
        '/ppp/active' => 'ppp_active',
        '/ppp/secret' => 'ppp_secrets',
        '/ip/hotspot/active' => 'hotspot_active',
        '/ip/dhcp-server/lease' => 'dhcp_leases',
        '/ip/address' => 'addresses',
        '/interface/wireless/registration-table' => 'registrations',
    ];

    public function print(string $path, array $where = []): array
    {
        $report = Cache::get(static::reportKey($this->device->id), []);
        $key = self::REPORT_PATHS[$path] ?? null;
        $rows = $key ? ($report[$key] ?? []) : [];
        if (isset($rows['.id']) || ($rows && ! array_is_list($rows))) {
            $rows = [$rows];
        }

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
        $this->enqueue($this->menu($path).' add '.$this->attrs($attributes));
    }

    public function update(string $path, array $where, array $attributes): int
    {
        $this->enqueue($this->menu($path).' set '.$this->find($where).' '.$this->attrs($attributes));

        return -1;
    }

    public function delete(string $path, array $where): int
    {
        $this->enqueue($this->menu($path).' remove '.$this->find($where));

        return -1;
    }

    public function command(string $path, array $attributes = []): array
    {
        $this->enqueue($this->menu($path).' '.$this->attrs($attributes));

        return [];
    }

    public function close(): void {}

    /** Devuelve y vacía la cola de comandos pendientes como script RouterOS. */
    public static function pullScript(NetworkDevice $device): string
    {
        $lines = Cache::pull(static::queueKey($device->id), []);
        $script = "# ID-Hub ".now()->format('Y-m-d H:i:s')." - {$device->name}\n";
        foreach ($lines as $line) {
            $script .= ":do { {$line} } on-error={}\n";
        }

        return $script;
    }

    public static function pendingCount(NetworkDevice $device): int
    {
        return count(Cache::get(static::queueKey($device->id), []));
    }

    protected function enqueue(string $line): void
    {
        $key = static::queueKey($this->device->id);
        $queue = Cache::get($key, []);
        $queue[] = $line;
        Cache::forever($key, $queue);
    }

    protected function menu(string $path): string
    {
        $parts = explode('/', trim($path, '/'));

        return '/'.implode(' ', $parts);
    }

    protected function find(array $where): string
    {
        if (! $where) {
            return '[find]';
        }
        $cond = collect($where)->map(fn ($v, $k) => $k.'='.$this->quote($v))->implode(' and ');

        return "[find where {$cond}]";
    }

    protected function attrs(array $attributes): string
    {
        return collect($attributes)->map(fn ($v, $k) => $k.'='.$this->quote($v))->implode(' ');
    }

    protected function quote(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $v).'"';
    }
}
