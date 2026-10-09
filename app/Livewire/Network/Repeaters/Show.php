<?php

namespace App\Livewire\Network\Repeaters;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Network\Concerns\ManagesRepeaters;
use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Outage;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Services\Messaging\MessageService;
use App\Services\Network\DemoRouterTransport;
use App\Services\Network\MonitoringService;
use App\Services\Network\NetworkCharts;
use App\Support\Audit;
use App\Support\Catalog;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ficha del repetidor: KPIs, panel de impacto de falla (sección 17), clientes, gráficas,
 * equipos, sectores, historial de fallas y mapa.
 */
class Show extends Component
{
    use ManagesRepeaters, Toasts, WithPagination;

    public Repeater $repeater;

    #[Url]
    public string $tab = 'resumen';

    #[Url(as: 'q', except: '')]
    public string $clientSearch = '';

    #[Url(as: 'estado', except: '')]
    public string $clientStatus = '';

    #[Url(as: 'conexion', except: '')]
    public string $clientOnline = '';

    public bool $showNotify = false;

    public string $notifyChannel = 'whatsapp';

    public function mount(Repeater $repeater): void
    {
        $this->repeater = $repeater;
    }

    public function updated($property): void
    {
        if (in_array($property, ['clientSearch', 'clientStatus', 'clientOnline'], true)) {
            $this->resetPage();
        }
    }

    protected function afterRepeaterSaved(Repeater $r): void
    {
        $this->repeater = $r->fresh();
    }

    /** Clientes con servicio vigente afectados por la falla. */
    protected function affectedQuery()
    {
        return Client::query()->where('repeater_id', $this->repeater->id)->whereIn('service_status', MonitoringService::LIVE);
    }

    public function notifyAffected(MessageService $messages): void
    {
        $this->authorize('network.manage');
        $zone = $this->repeater->zone?->name ?? $this->repeater->name;
        $channels = in_array($this->notifyChannel, ['whatsapp', 'sms', 'email', 'push'], true) ? [$this->notifyChannel] : null;
        $n = 0;
        $this->affectedQuery()->chunkById(200, function ($clients) use ($messages, $zone, $channels, &$n) {
            foreach ($clients as $c) {
                $messages->notify($c, 'outage', ['zona' => $zone], $channels);
                $n++;
            }
        });
        Audit::log('notify', "notificó la falla del repetidor {$this->repeater->code} a {$n} clientes", $this->repeater);
        $this->showNotify = false;
        $this->toast("Aviso de incidencia enviado a {$n} clientes afectados");
    }

    public function markRecovered(MonitoringService $monitoring): void
    {
        $this->authorize('network.manage');
        $monitoring->recoverRepeater($this->repeater, 'manual');
        Audit::log('recover', "marcó como restablecido el repetidor {$this->repeater->code}", $this->repeater);
        $this->repeater->refresh();
        $this->toast("Repetidor {$this->repeater->code} marcado como restablecido. Los clientes se reconectarán en los próximos ciclos de monitoreo.");
    }

    /** Solo demo: provoca una caída simulada para probar la detección de fallas y automatizaciones. */
    public function simulateDown(MonitoringService $monitoring): void
    {
        $this->authorize('network.manage');
        if (! in_array($this->repeater->router?->api_mode, [null, '', 'simulated'], true)) {
            $this->toast('La simulación de falla solo está disponible para equipos en modo simulador', 'error');

            return;
        }
        Cache::put(DemoRouterTransport::simDownKey($this->repeater->id), true, now()->addDay());
        $monitoring->pollTenant(tenant());
        $this->repeater->refresh();
        $this->toast("Falla simulada en {$this->repeater->code}: se generó la incidencia, la alerta y las automatizaciones", 'warning');
    }

    public function pollNow(MonitoringService $monitoring): void
    {
        $this->authorize('network.manage');
        $monitoring->pollTenant(tenant());
        $this->repeater->refresh();
        $this->toast('Monitoreo ejecutado');
    }

    public function render()
    {
        $r = $this->repeater->load(['tower', 'zone', 'router', 'accessPoint', 'technician', 'activeOutage.ticket', 'sectors.tower:id,code,lat,lng']);
        $r->loadCount([
            'clients as active_clients_count' => fn ($c) => $c->whereNotIn('service_status', ['cancelado', 'prospecto']),
            'clients as online_clients_count' => fn ($c) => $c->where('online', true)->whereNotIn('service_status', ['cancelado', 'prospecto']),
        ]);
        $util = $r->utilization();
        $rows = NetworkCharts::aggregate('repeater', [$r->id]);
        $last = $r->samples()->where('online', true)->latest('sampled_at')->first();
        $outage = $r->activeOutage;

        $data = [
            'r' => $r,
            'util' => $util,
            'last' => $last,
            'outage' => $outage,
            'affected' => $this->affectedQuery()->count(),
            'avgSignal' => Client::query()->where('repeater_id', $r->id)->where('online', true)->avg('signal_dbm'),
            'weakSignal' => Client::query()->where('repeater_id', $r->id)->where('online', true)->where('signal_dbm', '<', tenant()->setting('network.signal_min_dbm', -75))->count(),
            'isSimulated' => in_array($r->router?->api_mode, [null, '', 'simulated'], true) && $r->router,
        ];

        if ($r->status === 'offline' || $outage) {
            $data['relatedTickets'] = Ticket::query()->open()
                ->where(fn ($q) => $q->where('repeater_id', $r->id)->when($outage, fn ($w) => $w->orWhere('outage_id', $outage->id)))
                ->with('technician:id,name')->latest()->limit(10)->get();
        }

        if ($this->tab === 'resumen') {
            $data['charts'] = [
                'quality' => NetworkCharts::quality($rows, (float) tenant()->setting('network.latency_max_ms', 80)),
                'clients' => NetworkCharts::clients($rows),
                'traffic' => NetworkCharts::traffic($rows),
            ];
            $clients = Client::query()->where('repeater_id', $r->id)->whereNotNull('lat')->whereNotIn('service_status', ['cancelado'])
                ->get(['id', 'code', 'first_name', 'last_name', 'lat', 'lng', 'service_status', 'online', 'signal_dbm']);
            $markers = $clients->map(fn ($c) => ['lat' => (float) $c->lat, 'lng' => (float) $c->lng, 'type' => 'client', 'layer' => 'clients',
                'status' => $r->status === 'offline' && in_array($c->service_status, MonitoringService::LIVE) ? 'offline' : $c->mapStatus(),
                'popup' => '<b>'.e($c->full_name).'</b><br>#'.$c->code.' · '.Catalog::label('client_status', $c->service_status)
                    .($c->signal_dbm ? '<br>Señal '.$c->signal_dbm.' dBm' : '').'<br><a class="font-semibold text-brand-600" href="'.route('clients.show', $c->id).'">Ver cliente →</a>'])->all();
            if ($r->lat) {
                $markers[] = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng, 'type' => 'repeater', 'layer' => 'repeaters', 'size' => 34,
                    'status' => $r->status === 'online' && $util >= $r->alert_threshold ? 'warning' : $r->status, 'pulse' => $r->status === 'offline',
                    'popup' => '<b>'.e($r->code.' · '.$r->name).'</b>'];
            }
            $data['map'] = [
                'markers' => $markers,
                'circles' => $r->lat ? [['lat' => (float) $r->lat, 'lng' => (float) $r->lng, 'radius' => (float) $r->coverage_radius_km * 1000,
                    'color' => $r->status === 'offline' ? '#E5484D' : '#1A7FF5', 'opacity' => 0.07, 'popup' => 'Cobertura '.$r->coverage_radius_km.' km']] : [],
                'sectors' => $r->sectors->filter(fn ($s) => $s->tower?->lat)->map(fn ($s) => ['lat' => (float) $s->tower->lat, 'lng' => (float) $s->tower->lng,
                    'azimuth' => $s->azimuth, 'beamwidth' => $s->beamwidth, 'range_km' => (float) $s->range_km, 'popup' => e($s->name)])->values()->all(),
            ];
        }

        if ($this->tab === 'clientes') {
            $data['clients'] = Client::query()->where('repeater_id', $r->id)->with(['plan:id,name,download_mbps,upload_mbps,color', 'cpe:id,name,model'])
                ->search($this->clientSearch)
                ->when($this->clientStatus, fn ($q) => $q->where('service_status', $this->clientStatus))
                ->when($this->clientOnline !== '', fn ($q) => $q->where('online', $this->clientOnline === '1'))
                ->orderByDesc('online')->orderBy('code')->paginate(25);
        }

        if ($this->tab === 'equipos') {
            $data['devices'] = NetworkDevice::query()->where(fn ($q) => $q->where('repeater_id', $r->id)->whereNotIn('type', ['cpe'])
                ->orWhereIn('id', array_filter([$r->router_device_id, $r->ap_device_id])))->with('responsible:id,name')->orderBy('type')->get();
            $data['cpeStats'] = NetworkDevice::query()->where('repeater_id', $r->id)->whereIn('type', ['cpe', 'antenna'])
                ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
            $data['cpeTotal'] = Client::query()->where('repeater_id', $r->id)->whereNotNull('cpe_device_id')->whereNotIn('service_status', ['cancelado'])->count();
        }

        if ($this->tab === 'fallas') {
            $data['outages'] = Outage::query()->where('repeater_id', $r->id)->with(['technician:id,name', 'ticket:id,folio,status'])->latest('started_at')->limit(50)->get();
        }

        return view('livewire.network.repeaters.show', $data + $this->repeaterFormOptions())->title($r->code.' · '.$r->name);
    }
}
