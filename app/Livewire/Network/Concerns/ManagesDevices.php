<?php

namespace App\Livewire\Network\Concerns;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Models\Tower;
use App\Models\User;
use App\Support\InputRules;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de equipos de red en modal (secciones 10 y 39). Requiere Toasts.
 */
trait ManagesDevices
{
    public bool $showDeviceForm = false;

    public ?int $deviceId = null;

    public array $dform = [];

    public int $devicePickerVersion = 0;

    protected function deviceFields(): array
    {
        return ['type', 'name', 'manufacturer', 'model', 'serial', 'mac', 'ip', 'firmware', 'location', 'lat', 'lng', 'responsible_id',
            'tower_id', 'repeater_id', 'parent_id', 'status', 'installed_at', 'notes'];
    }

    public function newDevice(string $type = 'antenna'): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $this->deviceId = null;
        $this->dform = array_merge(array_fill_keys($this->deviceFields(), null), [
            'type' => array_key_exists($type, config('wisp.device_types')) ? $type : 'antenna',
            'status' => 'unknown', 'installed_at' => now()->toDateString(), 'client_code' => null,
        ]);
        $this->devicePickerVersion++;
        $this->showDeviceForm = true;
    }

    public function editDevice(int $id): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $d = NetworkDevice::with('client:id,code')->findOrFail($id);
        $this->deviceId = $d->id;
        $this->dform = $d->only($this->deviceFields());
        $this->dform['installed_at'] = $d->installed_at?->toDateString();
        $this->dform['lat'] = $d->lat !== null ? (string) (float) $d->lat : null;
        $this->dform['lng'] = $d->lng !== null ? (string) (float) $d->lng : null;
        $this->dform['client_code'] = $d->client?->code;
        $this->devicePickerVersion++;
        $this->showDeviceForm = true;
    }

    public function saveDevice(): void
    {
        $this->authorize('network.manage');
        $tid = tenant()->id;
        $data = $this->validate([
            'dform.type' => ['required', Rule::in(array_keys(config('wisp.device_types')))],
            'dform.name' => 'required|string|max:150|'.InputRules::TEXT,
            'dform.manufacturer' => 'nullable|string|max:100|'.InputRules::TEXT,
            'dform.model' => 'nullable|string|max:100|'.InputRules::TEXT,
            'dform.serial' => 'nullable|string|max:100|'.InputRules::CODE,
            'dform.mac' => ['nullable', 'max:17', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'dform.ip' => 'nullable|ip|max:15',
            'dform.firmware' => 'nullable|string|max:100|'.InputRules::TEXT,
            'dform.location' => 'nullable|string|max:190|'.InputRules::TEXT,
            'dform.lat' => 'nullable|numeric|between:-90,90',
            'dform.lng' => 'nullable|numeric|between:-180,180',
            'dform.responsible_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'dform.tower_id' => ['nullable', Rule::exists('towers', 'id')->where('tenant_id', $tid)],
            'dform.repeater_id' => ['nullable', Rule::exists('repeaters', 'id')->where('tenant_id', $tid)],
            'dform.parent_id' => ['nullable', Rule::exists('network_devices', 'id')->where('tenant_id', $tid)],
            'dform.status' => ['required', Rule::in(array_keys(config('wisp.device_status')))],
            'dform.installed_at' => 'nullable|date',
            'dform.notes' => 'nullable|string|max:2000',
            'dform.client_code' => 'nullable|string|max:30|'.InputRules::CODE,
        ], [], [
            'dform.name' => 'nombre', 'dform.mac' => 'MAC', 'dform.ip' => 'IP', 'dform.type' => 'tipo', 'dform.status' => 'estado',
            'dform.manufacturer' => 'fabricante', 'dform.model' => 'modelo', 'dform.serial' => 'número de serie', 'dform.location' => 'ubicación',
            'dform.client_code' => 'código de cliente', 'dform.notes' => 'notas',
        ])['dform'];

        $client = null;
        if (! empty($data['client_code'])) {
            $client = Client::query()->where('code', $data['client_code'])->first();
            if (! $client) {
                $this->addError('dform.client_code', 'No existe un cliente con ese código.');

                return;
            }
        }
        unset($data['client_code']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if ($data['mac']) {
            $data['mac'] = strtoupper(str_replace('-', ':', $data['mac']));
        }
        if ($this->deviceId && (int) $data['parent_id'] === $this->deviceId) {
            $data['parent_id'] = null;
        }
        $data['client_id'] = $client?->id;
        if ($client && ! $data['repeater_id']) {
            $data['repeater_id'] = $client->repeater_id;
        }
        if ($data['repeater_id'] && ! $data['tower_id']) {
            $data['tower_id'] = Repeater::find($data['repeater_id'])?->tower_id;
        }

        // Solo los MikroTik en operación cuentan contra el plan (alta, reactivación o cambio de tipo)
        $current = $this->deviceId ? NetworkDevice::findOrFail($this->deviceId) : null;
        if (tenant()->exceedsLimit('devices', $data, $current?->only(['type', 'status']))) {
            $this->addError('dform.status', tenant()->limitMessage('devices').' Puedes registrarlo En almacén.');

            return;
        }

        if ($current) {
            $d = $current;
            $d->update($data);
            $this->toast("Equipo {$d->name} actualizado");
        } else {
            $d = NetworkDevice::create($data);
            $this->toast("Equipo {$d->name} registrado");
        }
        // Equipos de cliente: queda como su antena/CPE si no tiene una
        if ($client && in_array($d->type, ['cpe', 'antenna'], true) && ! $client->cpe_device_id) {
            $client->update(['cpe_device_id' => $d->id]);
        }
        $this->showDeviceForm = false;
        $this->afterDeviceSaved($d);
    }

    protected function afterDeviceSaved(NetworkDevice $d): void {}

    protected function deviceFormOptions(): array
    {
        return [
            'deviceTypes' => config('wisp.device_types'),
            'towerOptions' => Tower::query()->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name]),
            'repeaterOptions' => Repeater::query()->orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]),
            'parentOptions' => NetworkDevice::query()->whereNotIn('type', ['cpe'])->orderBy('name')->limit(500)->pluck('name', 'id'),
            'responsibles' => User::forTenant()->active()->role(['tecnico', 'supervisor', 'admin'])->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
