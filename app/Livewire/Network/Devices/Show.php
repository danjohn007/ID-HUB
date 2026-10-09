<?php

namespace App\Livewire\Network\Devices;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Network\Concerns\ManagesDevices;
use App\Models\ConnectionEvent;
use App\Models\InstallationOrder;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Services\Network\NetworkCharts;
use Livewire\Component;

/**
 * Ficha de equipo: datos, métricas, monitoreo y cadena de equipo instalado (sección 39):
 * Cliente → Instalación → CPE → Repetidor → Torre.
 */
class Show extends Component
{
    use ManagesDevices, Toasts;

    public NetworkDevice $device;

    public function mount(NetworkDevice $device): void
    {
        $this->device = $device;
    }

    protected function afterDeviceSaved(NetworkDevice $d): void
    {
        $this->device = $d->fresh();
    }

    public function deleteDevice()
    {
        $this->authorize('network.manage');
        if ($this->device->type === 'mikrotik' && $this->device->routedClients()->exists()) {
            $this->toast('No se puede eliminar: el MikroTik tiene clientes asignados', 'error');

            return null;
        }
        $this->device->delete();
        session()->flash('success', 'Equipo eliminado');

        return $this->redirectRoute('network.devices.index', navigate: true);
    }

    public function render()
    {
        $d = $this->device->load(['client.plan', 'client.repeater', 'repeater.tower', 'repeater.zone', 'tower', 'parent', 'responsible', 'children']);
        $client = $d->client ?? $d->cpeClients()->with('plan', 'repeater')->first();
        $repeater = $d->repeater ?? $client?->repeater
            ?? Repeater::query()->where(fn ($q) => $q->where('ap_device_id', $d->id)->orWhere('router_device_id', $d->id))->with('tower')->first();
        $tower = $repeater?->tower ?? $d->tower;
        $installation = $client ? InstallationOrder::query()->where('client_id', $client->id)->latest('id')->first() : null;
        $servedRepeaters = Repeater::query()->withLoad()->where(fn ($q) => $q->where('ap_device_id', $d->id)->orWhere('router_device_id', $d->id))->get();

        // Monitoreo: muestras propias; si no hay (CPE), las de su repetidor como contexto
        $rows = NetworkCharts::aggregate('device', [$d->id]);
        $chartSource = 'device';
        if ($rows->isEmpty() && $repeater) {
            $rows = NetworkCharts::aggregate('repeater', [$repeater->id]);
            $chartSource = 'repeater';
        }

        return view('livewire.network.devices.show', [
            'd' => $d,
            'client' => $client,
            'repeater' => $repeater,
            'tower' => $tower,
            'installation' => $installation,
            'servedRepeaters' => $servedRepeaters,
            'chartSource' => $chartSource,
            'charts' => $rows->isEmpty() ? null : [
                'quality' => NetworkCharts::quality($rows, (float) tenant()->setting('network.latency_max_ms', 80)),
                'second' => $chartSource === 'device' && $rows->whereNotNull('cpu')->isNotEmpty() ? NetworkCharts::resources($rows) : NetworkCharts::clients($rows),
            ],
            'events' => $client ? ConnectionEvent::query()->where('client_id', $client->id)->latest('occurred_at')->limit(10)->get() : collect(),
        ] + $this->deviceFormOptions())->title($d->name);
    }
}
