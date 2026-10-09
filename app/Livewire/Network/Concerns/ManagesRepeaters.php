<?php

namespace App\Livewire\Network\Concerns;

use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Models\Tower;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de repetidores en modal (sección 12). Requiere el trait Toasts.
 */
trait ManagesRepeaters
{
    public bool $showRepeaterForm = false;

    public ?int $repeaterId = null;

    public array $rform = [];

    /** Cambia para re-crear el mapa selector del modal. */
    public int $pickerVersion = 0;

    protected function repeaterFields(): array
    {
        return ['code', 'name', 'tower_id', 'zone_id', 'location', 'lat', 'lng', 'capacity', 'alert_threshold', 'coverage_radius_km',
            'bandwidth_mbps', 'router_device_id', 'ap_device_id', 'technician_id', 'monthly_cost', 'notes'];
    }

    public function newRepeater(): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $this->repeaterId = null;
        $next = (int) Repeater::query()->selectRaw("MAX(CAST(SUBSTRING(code, 3) AS UNSIGNED)) m")->value('m') + 1;
        $this->rform = array_fill_keys($this->repeaterFields(), null) + [];
        $this->rform = array_merge($this->rform, [
            'code' => 'R-'.str_pad((string) $next, 2, '0', STR_PAD_LEFT), 'capacity' => 100, 'alert_threshold' => 85,
            'coverage_radius_km' => 3, 'monthly_cost' => 0,
        ]);
        $this->pickerVersion++;
        $this->showRepeaterForm = true;
    }

    public function editRepeater(int $id): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $r = Repeater::findOrFail($id);
        $this->repeaterId = $r->id;
        $this->rform = $r->only($this->repeaterFields());
        foreach (['lat', 'lng', 'coverage_radius_km', 'bandwidth_mbps', 'monthly_cost'] as $k) {
            $this->rform[$k] = $this->rform[$k] !== null ? (string) (float) $this->rform[$k] : null;
        }
        $this->pickerVersion++;
        $this->showRepeaterForm = true;
    }

    /** Al elegir torre se sugieren coordenadas y zona. */
    public function updatedRformTowerId($value): void
    {
        if ($value && ($t = Tower::find($value))) {
            if (! $this->rform['lat'] && $t->lat) {
                $this->rform['lat'] = (string) (float) $t->lat;
                $this->rform['lng'] = (string) (float) $t->lng;
                $this->pickerVersion++;
            }
            $this->rform['zone_id'] = $this->rform['zone_id'] ?: $t->zone_id;
        }
    }

    public function saveRepeater(): void
    {
        $this->authorize('network.manage');
        $tid = tenant()->id;
        $data = $this->validate([
            'rform.code' => ['required', 'string', 'max:30', Rule::unique('repeaters', 'code')->where('tenant_id', $tid)->ignore($this->repeaterId)],
            'rform.name' => 'required|string|max:150',
            'rform.tower_id' => ['nullable', Rule::exists('towers', 'id')->where('tenant_id', $tid)],
            'rform.zone_id' => ['nullable', Rule::exists('zones', 'id')->where('tenant_id', $tid)],
            'rform.location' => 'nullable|string|max:190',
            'rform.lat' => 'nullable|numeric|between:-90,90|required_with:rform.lng',
            'rform.lng' => 'nullable|numeric|between:-180,180|required_with:rform.lat',
            'rform.capacity' => 'required|integer|min:1|max:100000',
            'rform.alert_threshold' => 'required|integer|min:10|max:100',
            'rform.coverage_radius_km' => 'required|numeric|min:0.1|max:100',
            'rform.bandwidth_mbps' => 'nullable|numeric|min:0|max:100000',
            'rform.router_device_id' => ['nullable', Rule::exists('network_devices', 'id')->where('tenant_id', $tid)->where('type', 'mikrotik')],
            'rform.ap_device_id' => ['nullable', Rule::exists('network_devices', 'id')->where('tenant_id', $tid)],
            'rform.technician_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'rform.monthly_cost' => 'nullable|numeric|min:0|max:10000000',
            'rform.notes' => 'nullable|string|max:2000',
        ], [], [
            'rform.code' => 'ID', 'rform.name' => 'nombre', 'rform.capacity' => 'capacidad', 'rform.alert_threshold' => 'umbral de alerta',
            'rform.coverage_radius_km' => 'radio de cobertura', 'rform.lat' => 'latitud', 'rform.lng' => 'longitud',
        ])['rform'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['monthly_cost'] ??= 0;

        if ($this->repeaterId) {
            $r = Repeater::findOrFail($this->repeaterId);
            $r->update($data);
            $this->toast("Repetidor {$r->code} actualizado");
        } else {
            $r = Repeater::create($data + ['status' => 'online']);
            $this->toast("Repetidor {$r->code} registrado");
        }
        if ($r->ap_device_id) {
            NetworkDevice::whereKey($r->ap_device_id)->whereNull('repeater_id')->update(['repeater_id' => $r->id]);
        }
        $this->showRepeaterForm = false;
        $this->afterRepeaterSaved($r);
    }

    protected function afterRepeaterSaved(Repeater $r): void {}

    protected function repeaterFormOptions(): array
    {
        return [
            'towers' => Tower::query()->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name]),
            'zones' => Zone::query()->orderBy('name')->pluck('name', 'id'),
            'routerOptions' => NetworkDevice::mikrotik()->orderBy('name')->pluck('name', 'id'),
            'apOptions' => NetworkDevice::query()->whereIn('type', ['ap', 'antenna', 'router', 'other'])->orderBy('name')->limit(500)->get()
                ->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->ip ? ' · '.$d->ip : '')]),
            'technicians' => User::forTenant()->active()->role(['tecnico', 'supervisor'])->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
