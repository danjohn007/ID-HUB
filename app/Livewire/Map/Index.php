<?php

namespace App\Livewire\Map;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Outage;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Models\Zone;
use App\Support\Catalog;
use App\Support\DemandAnalysis;
use App\Support\MapData;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mapa inteligente (secciones 16, 17, 18 y 20): capas operativas, impacto de fallas
 * y zonas de alta demanda con baja cobertura.
 */
#[Title('Mapa inteligente')]
class Index extends Component
{
    public const LAYERS = [
        'clients' => ['Clientes', 'home', '#22A06B'],
        'towers' => ['Torres', 'building-office-2', '#F7881F'],
        'repeaters' => ['Repetidores', 'wifi', '#0B1B3A'],
        'devices' => ['Antenas', 'signal', '#3390F8'],
        'technicians' => ['Técnicos', 'wrench-screwdriver', '#1A7FF5'],
        'collectors' => ['Cobradores', 'banknotes', '#6048D0'],
        'tickets' => ['Tickets', 'ticket', '#E5484D'],
        'installations' => ['Instalaciones', 'clipboard-document-check', '#00A0A8'],
        'prospects' => ['Prospectos', 'user-plus', '#6048D0'],
        'coverage' => ['Zonas de cobertura', 'viewfinder-circle', '#1A7FF5'],
        'zones' => ['Zonas (polígonos)', 'map', '#0B1B3A'],
        'demand' => ['Concentración de demanda', 'fire', '#F7881F'],
    ];

    #[Url(as: 'capas', except: '')]
    public string $layersParam = '';

    public array $layers = ['clients', 'towers', 'repeaters', 'technicians', 'tickets', 'installations', 'coverage', 'zones'];

    #[Url(as: 'zona', except: '')]
    public string $filterZone = '';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'repetidor', except: '')]
    public string $filterRepeater = '';

    #[Url(as: 'panel', except: 'fallas')]
    public string $panel = 'fallas';

    public function mount(): void
    {
        if ($this->layersParam !== '') {
            $this->layers = array_values(array_intersect(explode(',', $this->layersParam), array_keys(self::LAYERS)));
        }
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'layers') || str_starts_with($property, 'filter')) {
            $this->layers = array_values(array_intersect($this->layers, array_keys(self::LAYERS)));
            $this->layersParam = implode(',', $this->layers);
            $this->dispatch('map-data-main-map', data: $this->mapData());
        }
    }

    public function preset(string $name): void
    {
        $this->layers = match ($name) {
            'red' => ['repeaters', 'towers', 'devices', 'coverage', 'tickets', 'zones'],
            'campo' => ['technicians', 'collectors', 'tickets', 'installations'],
            'expansion' => ['prospects', 'demand', 'coverage', 'repeaters', 'clients', 'zones'],
            default => array_keys(self::LAYERS),
        };
        $this->updated('layers');
    }

    public function clearFilters(): void
    {
        $this->reset('filterZone', 'filterStatus', 'filterRepeater');
        $this->updated('filter');
    }

    /**
     * Posiciones de técnicos y cobradores en vivo (wire:poll cada 30 s): solo envía esas capas al mapa,
     * sin volver a renderizar el componente ni redibujar el resto.
     */
    #[Renderless]
    public function refreshStaff(): void
    {
        $layers = array_values(array_intersect(['technicians', 'collectors'], $this->layers));
        if (! $layers) {
            return;
        }
        $this->dispatch('map-layer-main-map', layers: $layers,
            markers: array_values(array_filter(MapData::staff(), fn ($m) => in_array($m['layer'], $layers, true))));
    }

    /** Polígonos de las zonas (zones.polygon = [[lat, lng], ...]). */
    protected function zonePolygons(): array
    {
        return Zone::query()->whereNotNull('polygon')->when($this->filterZone, fn ($q) => $q->whereKey($this->filterZone))
            ->withCount(['clients' => fn ($q) => $q->whereNotIn('service_status', ['cancelado', 'prospecto'])])
            ->get(['id', 'name', 'community', 'color', 'polygon'])
            ->map(function ($z) {
                $points = collect(is_array($z->polygon) ? $z->polygon : [])
                    ->filter(fn ($p) => is_array($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]))
                    ->map(fn ($p) => [(float) $p[0], (float) $p[1]])->values()->all();

                return count($points) >= 3 ? [
                    'points' => $points, 'color' => $z->color ?: '#1A7FF5', 'layer' => 'zones',
                    'popup' => '<b>'.e($z->name).'</b>'.($z->community ? '<br>'.e($z->community) : '').'<br>'.number_format($z->clients_count).' clientes',
                ] : null;
            })->filter()->values()->all();
    }

    protected function devices(): array
    {
        return NetworkDevice::query()->whereNotNull('lat')->whereNull('client_id')
            ->whereNotIn('type', ['cpe'])->whereNotIn('status', ['retired', 'in_stock'])
            ->get(['id', 'name', 'type', 'model', 'status', 'lat', 'lng', 'ip', 'signal_dbm', 'last_seen_at'])
            ->map(fn ($d) => [
                'lat' => (float) $d->lat + 0.00015, 'lng' => (float) $d->lng - 0.00015, 'type' => 'device', 'layer' => 'devices',
                'status' => in_array($d->status, ['online', 'warning', 'offline'], true) ? $d->status : 'unknown', 'size' => 22,
                'popup' => '<b>'.e($d->name).'</b><br>'.Catalog::label('device_types', $d->type).($d->model ? ' · '.e($d->model) : '')
                    .'<br>'.Catalog::label('device_status', $d->status).($d->ip ? ' · '.e($d->ip) : '')
                    .($d->last_seen_at ? '<br>Última comunicación '.$d->last_seen_at->diffForHumans() : ''),
            ])->all();
    }

    protected function mapData(): array
    {
        $on = array_flip($this->layers);
        $markers = [];
        if (isset($on['clients'])) {
            $markers = array_merge($markers, MapData::clients(array_filter([
                'zone_id' => $this->filterZone, 'status' => $this->filterStatus, 'repeater_id' => $this->filterRepeater,
            ])));
        }
        if (isset($on['repeaters'])) {
            $markers = array_merge($markers, $this->filteredRepeaters());
        }
        if (isset($on['towers'])) {
            $markers = array_merge($markers, MapData::towers());
        }
        if (isset($on['devices'])) {
            $markers = array_merge($markers, $this->devices());
        }
        if (isset($on['technicians']) || isset($on['collectors'])) {
            $markers = array_merge($markers, array_values(array_filter(MapData::staff(), fn ($m) => isset($on[$m['layer']]))));
        }
        if (isset($on['tickets'])) {
            $markers = array_merge($markers, MapData::tickets());
        }
        if (isset($on['installations'])) {
            $markers = array_merge($markers, MapData::installations());
        }
        if (isset($on['prospects'])) {
            $markers = array_merge($markers, MapData::prospects());
        }

        return [
            'markers' => $markers,
            'circles' => isset($on['coverage']) ? MapData::coverageCircles() : [],
            'polygons' => isset($on['zones']) ? $this->zonePolygons() : [],
            'sectors' => isset($on['coverage']) ? MapData::sectors() : [],
            'heat' => isset($on['demand']) ? (new DemandAnalysis)->heat() : [],
            'cluster' => true,
            'fit' => false,
        ];
    }

    protected function filteredRepeaters(): array
    {
        if (! $this->filterZone && ! $this->filterRepeater) {
            return MapData::repeaters();
        }
        $ids = Repeater::query()->when($this->filterZone, fn ($q) => $q->where('zone_id', $this->filterZone))
            ->when($this->filterRepeater, fn ($q) => $q->where('id', $this->filterRepeater))->whereNotNull('lat')->get(['lat', 'lng'])
            ->map(fn ($r) => round((float) $r->lat, 6).','.round((float) $r->lng, 6))->flip();

        return array_values(array_filter(MapData::repeaters(), fn ($m) => isset($ids[round($m['lat'], 6).','.round($m['lng'], 6)])));
    }

    /** Fallas activas con su impacto (sección 17). Incluye repetidores caídos sin incidencia registrada. */
    protected function outages(): array
    {
        $outages = Outage::query()->whereNull('ended_at')->with(['repeater.zone', 'repeater.technician', 'zone', 'technician', 'device'])->latest('started_at')->get();
        $covered = $outages->pluck('repeater_id')->filter()->all();
        $downRepeaters = Repeater::query()->withLoad()->whereIn('status', ['offline'])->whereNotIn('id', $covered)->with('zone', 'technician')->get();

        $rows = [];
        foreach ($outages as $o) {
            $r = $o->repeater;
            $affected = $r ? $r->clients()->whereNotIn('service_status', ['cancelado', 'prospecto'])->count() : (int) $o->affected_clients;
            $tickets = Ticket::query()->open()
                ->where(fn ($q) => $q->where('outage_id', $o->id)->when($o->ticket_id, fn ($w) => $w->orWhere('id', $o->ticket_id))
                    ->when($r, fn ($w) => $w->orWhere('repeater_id', $r->id)))
                ->get(['id', 'folio', 'priority', 'subject']);
            $rows[] = [
                'key' => 'o'.$o->id,
                'title' => $r ? $r->code.' · '.$r->name : ($o->device?->name ?? 'Equipo'),
                'code' => $r?->code ?? $o->device?->name,
                'affected' => max($affected, (int) $o->affected_clients),
                'zone' => $o->zone?->name ?? $r?->zone?->name,
                'technician' => $o->technician?->name ?? $r?->technician?->name,
                'last' => $o->last_communication_at ?? $r?->last_seen_at,
                'since' => $o->started_at,
                'cause' => $o->cause,
                'tickets' => $tickets,
                'lat' => $r?->lat ? (float) $r->lat : ($o->device?->lat ? (float) $o->device->lat : null),
                'lng' => $r?->lng ? (float) $r->lng : ($o->device?->lng ? (float) $o->device->lng : null),
                'url' => $r && Route::has('network.repeaters.show') ? route('network.repeaters.show', $r) : null,
            ];
        }
        foreach ($downRepeaters as $r) {
            $rows[] = [
                'key' => 'r'.$r->id, 'title' => $r->code.' · '.$r->name, 'code' => $r->code, 'affected' => $r->clientLoad(),
                'zone' => $r->zone?->name, 'technician' => $r->technician?->name, 'last' => $r->last_seen_at, 'since' => $r->down_since ?? $r->last_seen_at,
                'cause' => 'Sin incidencia registrada',
                'tickets' => Ticket::query()->open()->where('repeater_id', $r->id)->get(['id', 'folio', 'priority', 'subject']),
                'lat' => $r->lat ? (float) $r->lat : null, 'lng' => $r->lng ? (float) $r->lng : null,
                'url' => Route::has('network.repeaters.show') ? route('network.repeaters.show', $r) : null,
            ];
        }

        return collect($rows)->sortByDesc('affected')->values()->all();
    }

    public function render()
    {
        $statusCounts = [
            'online' => Repeater::where('status', 'online')->count(),
            'warning' => Repeater::where('status', 'warning')->count(),
            'offline' => Repeater::where('status', 'offline')->count(),
            'unknown' => Repeater::whereNotIn('status', ['online', 'warning', 'offline'])->count(),
        ];
        $clientCounts = Client::query()->whereNotIn('service_status', ['cancelado'])
            ->selectRaw('SUM(CASE WHEN online = 1 THEN 1 ELSE 0 END) online_n, COUNT(*) total')->first();
        $demand = new DemandAnalysis;

        return view('livewire.map.index', [
            'map' => $this->mapData(),
            'layerDefs' => self::LAYERS,
            'outages' => $this->panel === 'fallas' ? $this->outages() : [],
            'outageCount' => Outage::whereNull('ended_at')->count() + Repeater::where('status', 'offline')->whereNotIn('id', Outage::whereNull('ended_at')->whereNotNull('repeater_id')->pluck('repeater_id'))->count(),
            'hotspots' => $this->panel === 'expansion' ? $demand->hotspots() : [],
            'zonesDemand' => $this->panel === 'expansion' ? $demand->byZone() : [],
            'statusCounts' => $statusCounts,
            'clientCounts' => $clientCounts,
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'repeaters' => Repeater::orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]),
            'center' => tenant()->setting('map.center') ?? [20.5888, -100.3899],
        ]);
    }
}
