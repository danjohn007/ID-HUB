<?php

namespace App\Livewire\Network\MikroTik;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Network\Concerns\ManagesRouters;
use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Services\MikroTik\PushTransport;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Registro de equipos MikroTik y su conexión (sección 11).
 */
#[Title('MikroTik')]
class Index extends Component
{
    use ManagesRouters, Toasts;

    public string $search = '';

    protected function afterRouterSaved(NetworkDevice $d): void
    {
        if (! $this->routerId) {
            $this->redirectRoute('network.mikrotik.show', $d, navigate: true);
        }
    }

    public function render()
    {
        $routers = NetworkDevice::mikrotik()->with('tower:id,code')
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")->orWhere('ip', 'like', "%{$this->search}%")->orWhere('api_host', 'like', "%{$this->search}%")))
            ->orderBy('name')->get();
        $repeaterRouter = Repeater::query()->pluck('router_device_id', 'id');
        $clients = Client::query()->whereNotIn('service_status', ['cancelado', 'prospecto'])->get(['id', 'router_device_id', 'repeater_id', 'online']);
        $stats = [];
        foreach ($clients as $c) {
            $rid = $c->router_device_id ?: ($repeaterRouter[$c->repeater_id] ?? null);
            if ($rid) {
                $stats[$rid]['total'] = ($stats[$rid]['total'] ?? 0) + 1;
                $stats[$rid]['online'] = ($stats[$rid]['online'] ?? 0) + ($c->online ? 1 : 0);
            }
        }

        return view('livewire.network.mikrotik.index', [
            'routers' => $routers,
            'stats' => $stats,
            'repeaterCounts' => Repeater::query()->selectRaw('router_device_id, COUNT(*) n')->groupBy('router_device_id')->pluck('n', 'router_device_id'),
            'pending' => $routers->where('api_mode', 'push')->mapWithKeys(fn ($d) => [$d->id => PushTransport::pendingCount($d)]),
        ] + $this->routerFormOptions());
    }
}
