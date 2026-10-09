<?php

namespace App\Services\Network;

use App\Models\Outage;
use App\Services\MikroTik\SimulatedTransport;
use Illuminate\Support\Facades\Cache;

/**
 * Simulador enriquecido para el módulo de red (modo "simulated").
 *
 * Extiende SimulatedTransport para que el monitoreo tenga un comportamiento verosímil:
 *  - Las sesiones PPPoE varían con el tiempo (pocos clientes se desconectan/reconectan).
 *  - Los clientes de un repetidor con falla activa (outage sin cerrar) o con caída simulada
 *    no tienen sesión: así la demo de R-04 se mantiene hasta que un administrador la resuelve.
 *  - Algunas anomalías deterministas para revisión técnica (usuario no registrado, MAC desconocida,
 *    cambio de CPE), útiles para demostrar la detección de clientes con anomalías.
 */
class DemoRouterTransport extends SimulatedTransport
{
    /** Clave de caché para simular la caída de un repetidor (solo demo). */
    public static function simDownKey(int $repeaterId): string
    {
        return "network:sim:down:{$repeaterId}";
    }

    protected ?array $downRepeaters = null;

    protected function window(): int
    {
        return intdiv(time(), 300);
    }

    /** Número pseudoaleatorio estable en [0,1) para una clave. */
    protected function chance(string $key): float
    {
        return (crc32($key) % 100000) / 100000;
    }

    protected function downRepeaters(): array
    {
        if ($this->downRepeaters !== null) {
            return $this->downRepeaters;
        }
        $ids = Outage::withoutGlobalScopes()->where('tenant_id', $this->device->tenant_id)
            ->whereNull('ended_at')->whereNotNull('repeater_id')->pluck('repeater_id')->all();
        foreach (\App\Models\Repeater::withoutGlobalScopes()->where('tenant_id', $this->device->tenant_id)->pluck('id') as $rid) {
            if (Cache::get(static::simDownKey($rid))) {
                $ids[] = $rid;
            }
        }

        return $this->downRepeaters = array_values(array_unique(array_map('intval', $ids)));
    }

    /** ¿El cliente tiene sesión en esta ventana de 5 minutos? */
    protected function hasSession($c): bool
    {
        if (in_array($c->service_status, ['suspendido', 'cancelado', 'prospecto', 'instalacion_pendiente'], true)) {
            return false;
        }
        if ($c->repeater_id && in_array((int) $c->repeater_id, $this->downRepeaters(), true)) {
            return false;
        }
        $w = $this->window();
        if ($c->id % 41 === 0) {
            return false; // CPE con problema persistente (sin conexión)
        }
        $flappy = $c->id % 97 === 3;
        $r = $this->chance("{$c->id}:{$w}");
        if ($c->online) {
            return $r >= ($flappy ? 0.18 : 0.002);
        }
        $recentlyDown = $c->last_online_at && $c->last_online_at->gt(now()->subHours(2));

        return $r < ($recentlyDown ? 0.7 : 0.3);
    }

    protected function unknownMac(int $n): string
    {
        $h = strtoupper(substr(md5($this->device->id.'-'.$n), 0, 12));

        return implode(':', str_split($h, 2));
    }

    public function print(string $path, array $where = []): array
    {
        if (! in_array($path, ['/ppp/active', '/ppp/secret', '/interface/wireless/registration-table'], true)) {
            return parent::print($path, $where);
        }

        $rows = match ($path) {
            '/ppp/active' => $this->activeRows(),
            '/ppp/secret' => array_merge(parent::print('/ppp/secret'), $this->orphanSecrets()),
            '/interface/wireless/registration-table' => $this->registrationRows(),
        };

        return array_values(array_filter($rows, function ($row) use ($where) {
            foreach ($where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v)) {
                    return false;
                }
            }

            return true;
        }));
    }

    protected function activeRows(): array
    {
        $day = now()->format('Ymd');
        $rows = [];
        foreach ($this->clients() as $c) {
            if (! $this->hasSession($c)) {
                continue;
            }
            $mac = $c->mac_address;
            // Cambio de CPE ocasional (MAC distinta a la registrada)
            if ($mac && crc32($c->id.'-cpe-'.$day) % 400 === 0) {
                $mac = $this->unknownMac($c->id);
            }
            $rows[] = [
                '.id' => '*'.dechex($c->id), 'name' => $c->pppoe_user ?: $c->code, 'service' => 'pppoe',
                'caller-id' => $mac, 'address' => $c->ip_address,
                'uptime' => (crc32($c->id.'u') % 20 + 1).'d'.(crc32($c->id.'h') % 24).'h',
            ];
        }
        // Usuario conectado sin cliente registrado (solo en algunos equipos)
        if ($this->device->id % 2 === 1) {
            $rows[] = ['.id' => '*ff01', 'name' => 'prueba-tecnico', 'service' => 'pppoe', 'caller-id' => $this->unknownMac(1),
                'address' => '10.250.'.($this->device->id % 250).'.10', 'uptime' => '3h12m'];
        }

        return $rows;
    }

    protected function orphanSecrets(): array
    {
        $rows = [['.id' => '*fe01', 'name' => 'baja-'.(1000 + $this->device->id), 'service' => 'pppoe', 'profile' => 'default', 'comment' => '', 'disabled' => 'false']];
        if ($this->device->id % 2 === 1) {
            $rows[] = ['.id' => '*fe02', 'name' => 'prueba-tecnico', 'service' => 'pppoe', 'profile' => 'default', 'comment' => 'pruebas', 'disabled' => 'false'];
        }

        return $rows;
    }

    protected function registrationRows(): array
    {
        $rows = [];
        foreach ($this->clients() as $c) {
            if (! $c->mac_address || ! $this->hasSession($c)) {
                continue;
            }
            $signal = $c->signal_dbm !== null ? (float) $c->signal_dbm : -(58 + crc32('s'.$c->id) % 15);
            $rows[] = [
                'mac-address' => $c->mac_address, 'signal-strength' => $signal.'dBm',
                'tx-rate' => (54 + crc32('t'.$c->id) % 250).'Mbps', 'rx-rate' => (54 + crc32('r'.$c->id) % 250).'Mbps',
                'uptime' => (crc32($c->id.'u') % 20 + 1).'d',
            ];
        }
        if ($this->device->id % 2 === 0) {
            $rows[] = ['mac-address' => $this->unknownMac(2), 'signal-strength' => '-71dBm', 'tx-rate' => '65Mbps', 'rx-rate' => '58.5Mbps', 'uptime' => '47m'];
        }

        return $rows;
    }
}
