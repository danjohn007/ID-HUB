<?php

namespace App\Services\MikroTik;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Plan;

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

    public function registrations(): array
    {
        return $this->transport()->print('/interface/wireless/registration-table');
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
            foreach ($this->registrations() as $r) {
                if (strcasecmp($r['mac-address'] ?? '', $client->mac_address) === 0) {
                    $signal = (float) filter_var($r['signal-strength'] ?? '', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
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
