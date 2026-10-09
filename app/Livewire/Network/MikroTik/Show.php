<?php

namespace App\Livewire\Network\MikroTik;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Network\Concerns\ManagesRouters;
use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Services\MikroTik\MikroTikService;
use App\Services\MikroTik\PushTransport;
use App\Services\Network\MonitoringService;
use App\Services\Network\NetworkCharts;
use App\Services\Network\NetworkClient;
use App\Services\Network\PushAgentScript;
use App\Services\ServiceControl;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Consola de un MikroTik (sección 11): estado y recursos, usuarios PPPoE, Hotspot, DHCP,
 * direcciones IP, conexiones activas, clientes asignados con acciones y agente push.
 * Los errores de comunicación se muestran sin romper la página.
 */
class Show extends Component
{
    use ManagesRouters, Toasts, WithPagination;

    public NetworkDevice $device;

    #[Url]
    public string $tab = 'estado';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'filtro', except: '')]
    public string $filter = '';

    public int $interval = 2;

    public ?array $syncResult = null;

    public function mount(NetworkDevice $device): void
    {
        abort_unless($device->type === 'mikrotik', 404);
        $this->device = $device;
    }

    public function updatedTab(): void
    {
        $this->reset('search', 'filter');
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    protected function afterRouterSaved(NetworkDevice $d): void
    {
        $this->device = $d->fresh();
    }

    protected function mt(): MikroTikService
    {
        return NetworkClient::mikrotik($this->device);
    }

    public function sync(MonitoringService $monitoring): void
    {
        $this->authorize('network.manage');
        $r = $monitoring->syncRouter($this->device);
        $this->device->refresh();
        $this->syncResult = $r;
        if (! $r['ok']) {
            $this->toast('No se pudo sincronizar: '.$r['error'], 'error');

            return;
        }
        $this->toast("Sincronizado: {$r['online']} de {$r['managed']} clientes en línea · {$r['came_online']} reconectados · {$r['went_offline']} desconectados");
    }

    public function test(): void
    {
        $this->testRouter($this->device->id);
        $this->device->refresh();
    }

    // ---------------------------------------------------------------- Acciones por cliente

    protected function client(int $id): Client
    {
        return Client::query()->findOrFail($id);
    }

    public function suspendClient(int $id, ServiceControl $service): void
    {
        $this->authorize('service.control');
        $e = $service->suspend($this->client($id), 'Suspensión manual desde MikroTik');
        $this->toast('Servicio suspendido. '.$e->network_result, $e->network_applied ? 'success' : 'warning');
    }

    public function reactivateClient(int $id, ServiceControl $service): void
    {
        $this->authorize('service.control');
        $e = $service->reconnect($this->client($id), 'Reconexión manual desde MikroTik', 'manual_nofee');
        $this->toast('Servicio reactivado. '.$e->network_result, $e->network_applied ? 'success' : 'warning');
    }

    public function applyPlan(int $id, ServiceControl $service): void
    {
        $this->authorize('service.control');
        $c = $this->client($id)->load('plan');
        [$ok, $result] = $service->onRouter($c, fn (MikroTikService $mt) => $mt->applyPlan($c));
        $this->toast(($ok ? 'Plan aplicado: ' : 'No se aplicó: ').$result, $ok ? 'success' : 'error');
    }

    public function provisionClient(int $id, ServiceControl $service): void
    {
        $this->authorize('service.control');
        $c = $this->client($id)->load('plan');
        [$ok, $result] = $service->onRouter($c, fn (MikroTikService $mt) => $mt->provisionClient($c));
        $this->toast($ok ? $result : 'No se aplicó: '.$result, $ok ? 'success' : 'error');
    }

    public function clearQueue(): void
    {
        $this->authorize('network.manage');
        Cache::forget(PushTransport::queueKey($this->device->id));
        $this->toast('Cola de comandos vaciada', 'warning');
    }

    // ---------------------------------------------------------------- Datos

    /** Ejecuta una consulta al equipo capturando errores. */
    protected function fetch(callable $fn, ?string &$error)
    {
        try {
            return $fn($this->mt());
        } catch (\Throwable $e) {
            $error = NetworkClient::friendlyError($e);

            return [];
        }
    }

    protected function clientsIndex()
    {
        return Client::query()->get(['id', 'code', 'first_name', 'last_name', 'pppoe_user', 'ip_address', 'mac_address', 'service_status', 'router_device_id', 'repeater_id']);
    }

    public function render()
    {
        $d = $this->device;
        $error = null;
        $data = ['d' => $d->loadMissing('tower:id,code,name')];

        $repeaterIds = Repeater::query()->where('router_device_id', $d->id)->pluck('id');
        $assigned = Client::query()->where(fn ($q) => $q->where('router_device_id', $d->id)->orWhere(fn ($w) => $w->whereNull('router_device_id')->whereIn('repeater_id', $repeaterIds)));
        $data['assignedCount'] = (clone $assigned)->whereNotIn('service_status', ['cancelado'])->count();
        $data['onlineCount'] = (clone $assigned)->where('online', true)->count();
        $data['repeaters'] = Repeater::query()->withLoad()->where('router_device_id', $d->id)->orderBy('code')->get();
        $data['pending'] = $d->api_mode === 'push' ? PushTransport::pendingCount($d) : 0;

        $match = function (array $rows, string $userKey, string $macKey = 'mac-address', string $ipKey = 'address') {
            $clients = $this->clientsIndex();
            $byUser = $clients->filter(fn ($c) => $c->pppoe_user)->keyBy(fn ($c) => mb_strtolower($c->pppoe_user));
            $byMac = $clients->filter(fn ($c) => $c->mac_address)->keyBy(fn ($c) => MonitoringService::mac($c->mac_address));
            $byIp = $clients->filter(fn ($c) => $c->ip_address)->keyBy('ip_address');

            return collect($rows)->map(function ($r) use ($byUser, $byMac, $byIp, $userKey, $macKey, $ipKey) {
                $u = mb_strtolower((string) ($r[$userKey] ?? ''));
                $client = ($u !== '' ? $byUser->get($u) : null) ?? (! empty($r[$macKey]) ? $byMac->get(MonitoringService::mac($r[$macKey])) : null)
                    ?? (! empty($r[$ipKey]) ? $byIp->get($r[$ipKey]) : null);
                $macMismatch = $client && ! empty($r[$macKey]) && $client->mac_address && MonitoringService::mac($r[$macKey]) !== MonitoringService::mac($client->mac_address);

                return $r + ['_client' => $client, '_mac_mismatch' => $macMismatch];
            });
        };
        $filterRows = function ($rows) {
            $term = mb_strtolower(trim($this->search));
            if ($term !== '') {
                $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', array_filter($r, 'is_scalar'))), $term)
                    || ($r['_client'] && str_contains(mb_strtolower($r['_client']->full_name.' '.$r['_client']->code), $term)));
            }
            if ($this->filter === 'unregistered') {
                $rows = $rows->filter(fn ($r) => ! $r['_client'] || $r['_mac_mismatch']);
            }

            return $rows->values();
        };

        switch ($this->tab) {
            case 'estado':
                $data['info'] = $this->fetch(fn ($mt) => $mt->test(), $error);
                $data['res'] = $error ? [] : $this->fetch(fn ($mt) => $mt->resources(), $error);
                $data['interfaces'] = $error ? [] : $this->fetch(fn ($mt) => $mt->interfaces(), $error);
                $rows = NetworkCharts::aggregate('device', [$d->id]);
                $data['charts'] = $rows->isEmpty() ? null : ['traffic' => NetworkCharts::traffic($rows), 'resources' => NetworkCharts::resources($rows)];
                break;
            case 'pppoe':
                $secrets = $match($this->fetch(fn ($mt) => $mt->pppSecrets(), $error), 'name');
                $active = collect($error ? [] : $this->fetch(fn ($mt) => $mt->pppActive(), $error))->keyBy(fn ($r) => mb_strtolower($r['name'] ?? ''));
                $secrets = $secrets->map(fn ($s) => $s + ['_active' => $active->get(mb_strtolower($s['name'] ?? ''))]);
                $data['unregistered'] = $secrets->filter(fn ($s) => ! $s['_client'])->count();
                $data['activeCount'] = $active->count();
                $data['rows'] = $filterRows($secrets);
                break;
            case 'hotspot':
                $data['rows'] = $filterRows($match($this->fetch(fn ($mt) => $mt->hotspotActive(), $error), 'user'));
                break;
            case 'dhcp':
                $data['rows'] = $filterRows($match($this->fetch(fn ($mt) => $mt->dhcpLeases(), $error), 'host-name'));
                break;
            case 'ips':
                $data['rows'] = collect($this->fetch(fn ($mt) => $mt->ipAddresses(), $error));
                break;
            case 'conexiones':
                $conn = $match($this->fetch(fn ($mt) => $mt->activeConnections(), $error), 'user', 'mac', 'address');
                $regs = collect($error ? [] : $this->fetch(fn ($mt) => $mt->registrations(), $error))->keyBy(fn ($r) => MonitoringService::mac($r['mac-address'] ?? ''));
                $data['unregistered'] = $conn->filter(fn ($r) => ! $r['_client'] || $r['_mac_mismatch'])->count();
                $data['rows'] = $filterRows($conn->map(fn ($r) => $r + ['_reg' => $regs->get(MonitoringService::mac($r['mac'] ?? ''))]));
                break;
            case 'clientes':
                $data['clients'] = $assigned->with(['plan:id,name,download_mbps,upload_mbps,mikrotik_profile', 'repeater:id,code'])
                    ->search($this->search)
                    ->when($this->filter, fn ($q) => $this->filter === 'online' ? $q->where('online', true) : ($this->filter === 'offline' ? $q->where('online', false) : $q->where('service_status', $this->filter)))
                    ->whereNotIn('service_status', ['cancelado'])->orderBy('code')->paginate(25);
                break;
            case 'agente':
                $data['script'] = $d->push_token ? PushAgentScript::build($d, $this->interval) : null;
                $data['report'] = Cache::get(PushTransport::reportKey($d->id));
                $data['queue'] = Cache::get(PushTransport::queueKey($d->id), []);
                break;
        }
        if ($d->api_mode === 'simulated' || ! $d->api_mode) {
            $data['simLog'] = array_slice(Cache::get("mikrotik:sim:{$d->id}:log", []), 0, 8);
        }

        $tabs = ['estado' => 'Estado', 'pppoe' => 'Usuarios PPPoE', 'hotspot' => 'Hotspot', 'dhcp' => 'DHCP', 'ips' => 'Direcciones IP',
            'conexiones' => 'Conexiones activas', 'clientes' => 'Clientes asignados'];
        if ($d->api_mode === 'push') {
            $tabs['agente'] = 'Script de agente';
        }

        return view('livewire.network.mikrotik.show', $data + ['error' => $error, 'tabs' => $tabs] + $this->routerFormOptions())->title($d->name);
    }
}
