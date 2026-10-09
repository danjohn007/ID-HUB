<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\Toasts;
use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\User;
use App\Models\Zone;
use App\Services\BillingService;
use App\Services\CoverageService;
use App\Services\ReferralService;
use App\Services\ServiceControl;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Alta y edición de cliente (sección 4.1).
 */
class Edit extends Component
{
    use Toasts;

    public ?Client $client = null;

    public array $form = [];

    // Opciones del alta
    public bool $activateNow = true;

    public bool $chargeInstallation = true;

    public string $referralCode = '';

    public ?string $coverageMessage = null;

    public function mount(?Client $client = null): void
    {
        $this->client = $client?->exists ? $client : null;
        abort_unless(auth()->user()->can('clients.manage'), 403);

        $this->form = $this->client
            ? array_merge($this->client->only($this->fields()), [
                'installation_date' => $this->client->installation_date?->toDateString(),
                'pppoe_password' => $this->client->pppoe_password,
            ])
            : array_merge(array_fill_keys($this->fields(), null), [
                'code' => $this->nextCode(),
                'service_status' => 'activo',
                'billing_day' => (int) tenant()->setting('billing.billing_day', 1),
                'installation_date' => now()->toDateString(),
                'pppoe_password' => Str::lower(Str::random(8)),
            ]);
    }

    protected function fields(): array
    {
        return ['code', 'first_name', 'last_name', 'phone', 'whatsapp', 'email', 'curp', 'rfc', 'address', 'references', 'lat', 'lng',
            'zone_id', 'community', 'colonia', 'plan_id', 'installation_date', 'technician_id', 'collector_id', 'cpe_device_id', 'repeater_id',
            'router_device_id', 'ip_address', 'mac_address', 'pppoe_user', 'pppoe_password', 'service_status', 'billing_day', 'notes'];
    }

    protected function nextCode(): string
    {
        $max = (int) Client::withTrashed()->max(DB::raw('CAST(code AS UNSIGNED)'));

        return (string) max(1001, $max + 1);
    }

    protected function rules(): array
    {
        $id = $this->client?->id;
        $tid = tenant()->id;

        return [
            'form.code' => ['required', 'string', 'max:30', Rule::unique('clients', 'code')->where('tenant_id', $tid)->ignore($id)],
            'form.first_name' => 'required|string|max:120',
            'form.last_name' => 'nullable|string|max:120',
            'form.phone' => 'nullable|string|max:30',
            'form.whatsapp' => 'nullable|string|max:30',
            'form.email' => 'nullable|email|max:150',
            'form.curp' => 'nullable|string|max:20',
            'form.rfc' => 'nullable|string|max:20',
            'form.address' => 'nullable|string|max:190',
            'form.references' => 'nullable|string|max:190',
            'form.lat' => 'nullable|numeric|between:-90,90',
            'form.lng' => 'nullable|numeric|between:-180,180',
            'form.zone_id' => ['nullable', Rule::exists('zones', 'id')->where('tenant_id', $tid)],
            'form.community' => 'nullable|string|max:120',
            'form.colonia' => 'nullable|string|max:120',
            'form.plan_id' => ['required', Rule::exists('plans', 'id')->where('tenant_id', $tid)],
            'form.installation_date' => 'nullable|date',
            'form.technician_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'form.collector_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'form.cpe_device_id' => ['nullable', Rule::exists('network_devices', 'id')->where('tenant_id', $tid)],
            'form.repeater_id' => ['nullable', Rule::exists('repeaters', 'id')->where('tenant_id', $tid)],
            'form.router_device_id' => ['nullable', Rule::exists('network_devices', 'id')->where('tenant_id', $tid)],
            'form.ip_address' => 'nullable|ip',
            'form.mac_address' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'form.pppoe_user' => ['nullable', 'string', 'max:80', Rule::unique('clients', 'pppoe_user')->where('tenant_id', $tid)->ignore($id)],
            'form.pppoe_password' => 'nullable|string|max:80',
            'form.service_status' => ['required', Rule::in(array_keys(config('wisp.client_status')))],
            'form.billing_day' => 'required|integer|between:1,28',
            'form.notes' => 'nullable|string|max:2000',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.code' => 'ID de cliente', 'form.first_name' => 'nombre', 'form.plan_id' => 'plan', 'form.email' => 'correo',
            'form.ip_address' => 'IP', 'form.mac_address' => 'MAC', 'form.pppoe_user' => 'usuario PPPoE', 'form.billing_day' => 'día de facturación',
        ];
    }

    /** Al elegir ubicación sugiere repetidor, router y zona según cobertura. */
    public function updated($property): void
    {
        if (in_array($property, ['form.lat', 'form.lng']) && $this->form['lat'] && $this->form['lng']) {
            $cov = app(CoverageService::class)->check((float) $this->form['lat'], (float) $this->form['lng']);
            $this->coverageMessage = $cov['message'];
            if ($cov['repeater'] && ! $this->form['repeater_id']) {
                $this->form['repeater_id'] = $cov['repeater']->id;
                $this->form['router_device_id'] ??= $cov['repeater']->router_device_id;
                $this->form['zone_id'] ??= $cov['repeater']->zone_id;
            }
        }
        if ($property === 'form.repeater_id' && $this->form['repeater_id']) {
            $r = Repeater::find($this->form['repeater_id']);
            $this->form['router_device_id'] = $r?->router_device_id ?? $this->form['router_device_id'];
            $this->form['zone_id'] = $this->form['zone_id'] ?: $r?->zone_id;
        }
        if ($property === 'form.first_name' && ! $this->client && ! $this->form['pppoe_user']) {
            $this->form['pppoe_user'] = 'c'.$this->form['code'];
        }
    }

    public function save()
    {
        $data = $this->validate()['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if ($data['mac_address']) {
            $data['mac_address'] = strtoupper(str_replace('-', ':', $data['mac_address']));
        }
        $data['pppoe_user'] ??= 'c'.$data['code'];

        if (tenant()->exceedsLimit('clients', $data, $this->client?->only('service_status'))) {
            $this->addError('form.service_status', tenant()->limitMessage('clients').' Puedes guardarlo como Prospecto.');

            return null;
        }

        if ($this->client) {
            $planChanged = (int) $this->client->plan_id !== (int) $data['plan_id'];
            $newPlan = Plan::find($data['plan_id']);
            unset($data['plan_id']);
            $this->client->update($data);
            if ($planChanged && $newPlan) {
                app(ServiceControl::class)->changePlan($this->client, $newPlan);
            }
            $this->toast('Cliente actualizado');

            return $this->redirectRoute('clients.show', $this->client, navigate: true);
        }

        $client = Client::create($data);
        Audit::log('created', "registró cliente #{$client->code}", $client);
        app(ReferralService::class)->ensureCode($client);
        if ($this->referralCode) {
            app(ReferralService::class)->register($client, $this->referralCode)
                ?? $this->toast('El código de referido no es válido', 'warning');
        }
        if ($this->chargeInstallation && ($fee = (float) $client->plan?->installation_fee) > 0) {
            app(BillingService::class)->createCharge($client, 'instalacion', 'Instalación de servicio', $fee, now());
        }
        if ($this->activateNow && $client->service_status === 'activo') {
            app(ServiceControl::class)->activate($client);
        }
        app(BillingService::class)->recalculate($client->fresh());
        session()->flash('success', "Cliente #{$client->code} registrado");

        return $this->redirectRoute('clients.show', $client, navigate: true);
    }

    public function render()
    {
        return view('livewire.clients.edit', [
            'plans' => Plan::where('active', true)->orderBy('price')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->name} · {$p->speedLabel()} · ".money($p->price)]),
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'repeaters' => Repeater::withLoad()->orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->code} · {$r->name} ({$r->utilization()}%)"]),
            'routers' => NetworkDevice::mikrotik()->orderBy('name')->pluck('name', 'id'),
            'cpes' => NetworkDevice::query()->whereIn('type', ['cpe', 'antenna'])
                ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $this->client?->id))
                ->orderBy('name')->limit(300)->get()->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->mac ? " · {$d->mac}" : '')]),
            'technicians' => User::forTenant()->active()->role(['tecnico', 'supervisor'])->orderBy('name')->pluck('name', 'id'),
            'collectors' => User::forTenant()->active()->role('cobrador')->orderBy('name')->pluck('name', 'id'),
        ])->title($this->client ? 'Editar cliente' : 'Nuevo cliente');
    }
}
