<?php

namespace App\Livewire\Network;

use App\Livewire\Concerns\Toasts;
use App\Models\Alert;
use App\Models\Client;
use App\Models\MonitoringSample;
use App\Models\NetworkDevice;
use App\Models\Outage;
use App\Models\Repeater;
use App\Services\MetricsService;
use App\Services\Network\MonitoringService;
use App\Services\Network\NetworkCharts;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard de red (sección 32): equipos y clientes en línea, consumo, CPU, memoria,
 * latencia, packet loss, saturación por repetidor, fallas activas y alertas de red.
 */
#[Title('Monitoreo de red')]
class Index extends Component
{
    use Toasts;

    /** '' = toda la red, 'd:ID' = un MikroTik, 'r:ID' = un repetidor */
    #[Url(as: 'ver', except: '')]
    public string $scope = '';

    #[Url(as: 'alertas', except: '')]
    public string $alertGroup = '';

    public const ALERT_GROUPS = [
        'infra' => ['Infraestructura', ['repeater_down', 'repeater_saturated', 'device_offline', 'mass_disconnect']],
        'quality' => ['Calidad', ['high_latency', 'packet_loss', 'abnormal_signal']],
        'anomaly' => ['Anomalías', ['anomaly_mac', 'anomaly_unauthorized', 'anomaly_multi_device', 'anomaly_cpe_change', 'anomaly_duplicate_ip', 'anomaly_usage', 'anomaly_flapping']],
    ];

    public function updatedScope(): void
    {
        foreach ($this->charts() as $id => $options) {
            $this->dispatch('chart-data-net-'.$id, options: $options);
        }
    }

    public function pollNow(MonitoringService $monitoring): void
    {
        $this->authorize('network.manage');
        $s = $monitoring->pollTenant(tenant());
        $this->updatedScope();
        $this->dispatch('chart-data-net-saturation', options: NetworkCharts::saturation(Repeater::query()->withLoad()->orderBy('code')->get()));
        $msg = "Monitoreo ejecutado: {$s['routers_ok']} MikroTik respondieron, {$s['clients_online']} clientes en línea";
        $this->toast($s['routers_failed'] ? $msg." · {$s['routers_failed']} con error" : $msg, $s['routers_failed'] ? 'warning' : 'success');
    }

    public function acknowledge(int $id): void
    {
        $this->authorize('network.manage');
        Alert::query()->open()->whereKey($id)->update(['status' => 'acknowledged', 'acknowledged_by' => auth()->id()]);
        $this->toast('Alerta marcada como revisada');
    }

    /** [tipo, ids] de muestras para dispositivos y repetidores según el alcance elegido. */
    protected function scopeIds(): array
    {
        if (str_starts_with($this->scope, 'd:')) {
            $id = (int) substr($this->scope, 2);

            return [[$id], Repeater::query()->where('router_device_id', $id)->pluck('id')->all()];
        }
        if (str_starts_with($this->scope, 'r:')) {
            return [null, [(int) substr($this->scope, 2)]];
        }

        return [NetworkDevice::mikrotik()->pluck('id')->all(), null];
    }

    protected function charts(): array
    {
        [$devices, $repeaters] = $this->scopeIds();
        $rep = NetworkCharts::aggregate('repeater', $repeaters);
        $dev = $devices !== null ? NetworkCharts::aggregate('device', $devices) : $rep;
        $max = (float) tenant()->setting('network.latency_max_ms', 80);

        return [
            'traffic' => NetworkCharts::traffic($dev),
            'resources' => NetworkCharts::resources($dev),
            'quality' => NetworkCharts::quality($rep, $max),
            'clients' => NetworkCharts::clients($rep),
        ];
    }

    public function render(MetricsService $metrics)
    {
        $net = $metrics->networkStats();
        $routers = NetworkDevice::mikrotik()->orderBy('name')->get();
        $repeaters = Repeater::query()->withLoad()->orderBy('code')->get();

        // Valores actuales: última muestra de cada repetidor en la última hora
        $latest = MonitoringSample::query()->where('subject_type', 'repeater')->where('sampled_at', '>=', now()->subHour())
            ->where('online', true)->orderByDesc('sampled_at')->get()->unique('subject_id');
        $traffic = MonitoringSample::query()->where('subject_type', 'device')->where('sampled_at', '>=', now()->subHour())
            ->orderByDesc('sampled_at')->get()->unique('subject_id');

        $types = $this->alertGroup && isset(self::ALERT_GROUPS[$this->alertGroup])
            ? self::ALERT_GROUPS[$this->alertGroup][1]
            : collect(self::ALERT_GROUPS)->pluck(1)->flatten()->all();
        $alerts = Alert::query()->open()->whereIn('type', $types)
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")->latest('id')->limit(25)->get();
        $alertCounts = Alert::query()->open()->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type');

        return view('livewire.network.index', [
            'net' => $net,
            'routers' => $routers,
            'repeaters' => $repeaters,
            'now' => [
                'rx' => $traffic->sum('rx_bps') / 1e6,
                'tx' => $traffic->sum('tx_bps') / 1e6,
                'cpu' => $routers->whereNotNull('cpu')->avg('cpu'),
                'memory' => $routers->whereNotNull('memory')->avg('memory'),
                'latency' => $latest->whereNotNull('latency_ms')->avg('latency_ms'),
                'loss' => $latest->whereNotNull('packet_loss')->avg('packet_loss'),
            ],
            'charts' => $this->charts(),
            'saturation' => NetworkCharts::saturation($repeaters),
            'outages' => Outage::query()->whereNull('ended_at')->with(['repeater', 'zone:id,name', 'technician:id,name', 'ticket:id,folio,status'])->latest('started_at')->get(),
            'alerts' => $alerts,
            'alertCounts' => $alertCounts,
            'problemDevices' => NetworkDevice::query()->whereIn('status', ['offline', 'warning'])->whereNotIn('type', ['cpe', 'antenna'])
                ->with('repeater:id,code')->orderByRaw("FIELD(status, 'offline', 'warning')")->limit(12)->get(),
            'cpeProblems' => NetworkDevice::query()->whereIn('type', ['cpe', 'antenna'])->whereIn('status', ['offline', 'warning'])
                ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'lastPoll' => NetworkDevice::mikrotik()->max('last_sync_at'),
            'clientsAffected' => (int) Outage::query()->whereNull('ended_at')->sum('affected_clients'),
            'liveClients' => Client::query()->whereIn('service_status', MonitoringService::LIVE)->count(),
        ]);
    }
}
