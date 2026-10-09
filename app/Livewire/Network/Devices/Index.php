<?php

namespace App\Livewire\Network\Devices;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Livewire\Network\Concerns\ManagesDevices;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Services\ReportExporter;
use App\Support\Catalog;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inventario de equipos de red instalados (secciones 10 y 39): MikroTik, antenas, CPE, AP,
 * routers, switches, UPS, fuentes y otros.
 */
#[Title('Equipos de red')]
class Index extends Component
{
    use ManagesDevices, Toasts, WithTable;

    #[Url(as: 'tipo', except: '')]
    public string $filterType = '';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'repetidor', except: '')]
    public string $filterRepeater = '';

    protected array $sortable = ['name', 'type', 'ip', 'status', 'last_seen_at', 'installed_at', 'signal_dbm'];

    protected function query()
    {
        return NetworkDevice::query()
            ->with(['repeater:id,code', 'tower:id,code', 'client:id,code,first_name,last_name', 'responsible:id,name'])
            ->when($this->search, function ($q) {
                $like = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('ip', 'like', $like)->orWhere('mac', 'like', $like)
                    ->orWhere('model', 'like', $like)->orWhere('serial', 'like', $like)->orWhere('manufacturer', 'like', $like));
            })
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterStatus === 'offline', fn ($q) => $q->whereIn('status', ['offline', 'unknown']))
            ->when($this->filterStatus && $this->filterStatus !== 'offline', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterRepeater, fn ($q) => $q->where('repeater_id', $this->filterRepeater));
    }

    public function clearFilters(): void
    {
        $this->reset('filterType', 'filterStatus', 'filterRepeater', 'search');
        $this->resetPage();
    }

    public function retire(int $id): void
    {
        $this->authorize('network.manage');
        $d = NetworkDevice::findOrFail($id);
        $d->update(['status' => 'retired']);
        $this->toast("Equipo {$d->name} marcado como retirado", 'warning');
    }

    public function deleteDevice(int $id): void
    {
        $this->authorize('network.manage');
        $d = NetworkDevice::findOrFail($id);
        if ($d->type === 'mikrotik' && $d->routedClients()->exists()) {
            $this->toast('No se puede eliminar: el MikroTik tiene clientes asignados', 'error');

            return;
        }
        $d->delete();
        $this->toast("Equipo {$d->name} eliminado", 'warning');
    }

    public function export(string $format = 'xlsx')
    {
        return app(ReportExporter::class)->download('equipos', $format, [
            'Nombre' => 'name', 'Tipo' => fn ($d) => config('wisp.device_types.'.$d->type), 'Fabricante' => 'manufacturer', 'Modelo' => 'model',
            'Serie' => 'serial', 'MAC' => 'mac', 'IP' => 'ip', 'Firmware' => 'firmware', 'Ubicación' => 'location',
            'Repetidor' => 'repeater.code', 'Torre' => 'tower.code', 'Cliente' => 'client.code', 'Responsable' => 'responsible.name',
            'Estado' => fn ($d) => Catalog::label('device_status', $d->status), 'Instalación' => fn ($d) => $d->installed_at?->format('d/m/Y'),
            'Último contacto' => fn ($d) => $d->last_seen_at?->format('d/m/Y H:i'),
        ], $this->query()->orderBy('type')->orderBy('name')->get(), 'Equipos de red');
    }

    public function render()
    {
        $counts = NetworkDevice::query()->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type');
        $status = NetworkDevice::query()->whereNotIn('status', ['retired', 'in_stock'])->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.network.devices.index', [
            'devices' => $this->applySort($this->query(), 'name', 'asc')->paginate($this->perPage),
            'counts' => $counts,
            'status' => $status,
            'repeaterFilter' => Repeater::query()->orderBy('code')->pluck('code', 'id'),
        ] + $this->deviceFormOptions());
    }
}
