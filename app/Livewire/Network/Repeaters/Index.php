<?php

namespace App\Livewire\Network\Repeaters;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Livewire\Network\Concerns\ManagesRepeaters;
use App\Models\Repeater;
use App\Models\Tower;
use App\Models\Zone;
use App\Services\ReportExporter;
use App\Support\Catalog;
use App\Support\MapData;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Gestión de repetidores (sección 12): capacidad, clientes conectados, utilización y estado.
 */
#[Title('Repetidores')]
class Index extends Component
{
    use ManagesRepeaters, Toasts, WithTable;

    #[Url(as: 'vista', except: 'lista')]
    public string $view = 'lista';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'zona', except: '')]
    public string $filterZone = '';

    #[Url(as: 'torre', except: '')]
    public string $filterTower = '';

    protected array $sortable = ['code', 'name', 'capacity', 'active_clients_count', 'online_clients_count', 'status'];

    protected function query()
    {
        return Repeater::query()->withLoad()->with(['tower:id,code,name', 'zone:id,name', 'router:id,name,status', 'technician:id,name'])
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', "%{$this->search}%")->orWhere('name', 'like', "%{$this->search}%")->orWhere('location', 'like', "%{$this->search}%")))
            ->when($this->filterStatus === 'saturated', fn ($q) => $q->whereRaw('(SELECT COUNT(*) FROM clients c WHERE c.repeater_id = repeaters.id AND c.deleted_at IS NULL AND c.service_status NOT IN (\'cancelado\', \'prospecto\')) >= repeaters.capacity * repeaters.alert_threshold / 100'))
            ->when($this->filterStatus && $this->filterStatus !== 'saturated', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterZone, fn ($q) => $q->where('zone_id', $this->filterZone))
            ->when($this->filterTower, fn ($q) => $q->where('tower_id', $this->filterTower));
    }

    protected function afterRepeaterSaved(Repeater $r): void
    {
        if ($this->view === 'mapa') {
            $this->dispatch('map-data-repeaters-map', data: $this->mapData());
        }
    }

    public function deleteRepeater(int $id): void
    {
        $this->authorize('network.manage');
        $r = Repeater::withLoad()->findOrFail($id);
        if ($r->clientLoad() > 0) {
            $this->toast("No se puede eliminar {$r->code}: tiene {$r->clientLoad()} clientes asignados. Migra los clientes primero.", 'error');

            return;
        }
        $r->delete();
        $this->toast("Repetidor {$r->code} eliminado", 'warning');
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->orderBy('code')->get();

        return app(ReportExporter::class)->download('repetidores', $format, [
            'ID' => 'code', 'Nombre' => 'name', 'Torre' => 'tower.code', 'Zona' => 'zone.name', 'MikroTik' => 'router.name',
            'Clientes' => fn ($r) => $r->clientLoad(), 'Conectados' => 'online_clients_count', 'Capacidad' => 'capacity',
            'Utilización %' => fn ($r) => $r->utilization(), 'Estado' => fn ($r) => Catalog::label('device_status', $r->status),
            'Técnico' => 'technician.name', 'Costo mensual' => fn ($r) => (float) $r->monthly_cost,
        ], $rows, 'Repetidores');
    }

    protected function mapData(): array
    {
        return ['markers' => array_merge(MapData::repeaters(), MapData::towers()), 'circles' => MapData::coverageCircles()];
    }

    public function render()
    {
        $all = Repeater::query()->withLoad()->get();

        return view('livewire.network.repeaters.index', [
            'repeaters' => $this->applySort($this->query(), 'code', 'asc')->paginate($this->perPage),
            'summary' => [
                'total' => $all->count(),
                'online' => $all->where('status', 'online')->count(),
                'offline' => $all->where('status', 'offline')->count(),
                'saturated' => $all->filter(fn ($r) => $r->utilization() >= $r->alert_threshold)->count(),
                'clients' => $all->sum(fn ($r) => $r->clientLoad()),
                'capacity' => $all->sum('capacity'),
                'connected' => $all->sum('online_clients_count'),
            ],
            'zones' => Zone::query()->orderBy('name')->pluck('name', 'id'),
            'towerFilter' => Tower::query()->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name]),
            'map' => $this->view === 'mapa' ? $this->mapData() : null,
        ] + $this->repeaterFormOptions());
    }
}
