<?php

namespace App\Livewire\Network\Concerns;

use App\Models\NetworkDevice;
use App\Models\Tower;
use App\Services\Network\NetworkClient;
use App\Support\Audit;
use App\Support\InputRules;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de MikroTik con datos de conexión (sección 11). La contraseña se guarda
 * cifrada y nunca se envía de regreso al navegador. Requiere Toasts.
 */
trait ManagesRouters
{
    public bool $showRouterForm = false;

    public ?int $routerId = null;

    public array $mform = [];

    public bool $routerHasPassword = false;

    public function newRouter(): void
    {
        $this->authorize('network.manage');
        if (tenant()->exceedsLimit('devices', ['type' => 'mikrotik', 'status' => 'unknown'])) {
            $this->toast(tenant()->limitMessage('devices'), 'error');

            return;
        }
        $this->resetValidation();
        $this->routerId = null;
        $this->routerHasPassword = false;
        $this->mform = ['name' => '', 'model' => '', 'ip' => '', 'api_mode' => 'api', 'api_host' => '', 'api_port' => '', 'api_ssl' => false,
            'api_user' => 'admin', 'password' => '', 'service_method' => 'pppoe', 'suspended_list' => 'WISP_MOROSOS', 'location' => '',
            'tower_id' => null, 'notes' => ''];
        $this->showRouterForm = true;
    }

    public function editRouter(int $id): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $d = NetworkDevice::mikrotik()->findOrFail($id);
        $this->routerId = $d->id;
        $this->routerHasPassword = (string) $d->api_password !== '';
        $this->mform = $d->only(['name', 'model', 'ip', 'api_mode', 'api_host', 'api_port', 'api_user', 'service_method', 'suspended_list', 'location', 'tower_id', 'notes'])
            + ['api_ssl' => (bool) $d->api_ssl, 'password' => ''];
        $this->mform['api_mode'] ??= 'simulated';
        $this->showRouterForm = true;
    }

    public function saveRouter(): void
    {
        $this->authorize('network.manage');
        $tid = tenant()->id;
        $data = $this->validate([
            'mform.name' => ['required', 'string', 'max:120', InputRules::TEXT, Rule::unique('network_devices', 'name')->where('tenant_id', $tid)->where('type', 'mikrotik')->whereNull('deleted_at')->ignore($this->routerId)],
            'mform.model' => 'nullable|string|max:100|'.InputRules::TEXT,
            'mform.ip' => 'nullable|ip|max:15',
            'mform.api_mode' => ['required', Rule::in(array_keys(config('wisp.api_modes')))],
            'mform.api_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/', Rule::requiredIf(fn () => in_array($this->mform['api_mode'], ['api', 'rest'], true) && empty($this->mform['ip']))],
            'mform.api_port' => 'nullable|integer|between:1,65535',
            'mform.api_ssl' => 'boolean',
            'mform.api_user' => ['nullable', 'string', 'max:100', InputRules::USERNAME, Rule::requiredIf(fn () => in_array($this->mform['api_mode'], ['api', 'rest'], true))],
            'mform.password' => 'nullable|string|max:190',
            'mform.service_method' => ['required', Rule::in(array_keys(config('wisp.service_methods')))],
            'mform.suspended_list' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-.]+$/'],
            'mform.location' => 'nullable|string|max:190|'.InputRules::TEXT,
            'mform.tower_id' => ['nullable', Rule::exists('towers', 'id')->where('tenant_id', $tid)],
            'mform.notes' => 'nullable|string|max:2000',
        ], [
            'mform.api_host.required' => 'Indica el host o la IP del equipo.',
        ], [
            'mform.name' => 'nombre', 'mform.api_host' => 'host', 'mform.api_port' => 'puerto', 'mform.api_user' => 'usuario',
            'mform.suspended_list' => 'address-list de suspendidos', 'mform.api_mode' => 'modo de conexión', 'mform.model' => 'modelo',
            'mform.ip' => 'IP de gestión', 'mform.location' => 'ubicación', 'mform.password' => 'contraseña',
        ])['mform'];

        $password = $data['password'];
        unset($data['password']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['api_ssl'] = (bool) $data['api_ssl'];
        if ($password !== null && $password !== '') {
            $data['api_password'] = $password;
        }

        if ($this->routerId) {
            $d = NetworkDevice::mikrotik()->findOrFail($this->routerId);
            $d->fill($data);
            if (! $d->push_token) {
                $d->push_token = Str::random(40);
            }
            $d->save();
            $this->toast("MikroTik {$d->name} actualizado");
        } else {
            if (tenant()->exceedsLimit('devices', ['type' => 'mikrotik', 'status' => 'unknown'])) {
                $this->addError('mform.name', tenant()->limitMessage('devices'));

                return;
            }
            $d = NetworkDevice::create($data + ['type' => 'mikrotik', 'manufacturer' => 'MikroTik', 'status' => 'unknown', 'push_token' => Str::random(40), 'installed_at' => now()->toDateString()]);
            $this->toast("MikroTik {$d->name} registrado");
        }
        $this->showRouterForm = false;
        $this->mform['password'] = '';
        $this->afterRouterSaved($d);
    }

    protected function afterRouterSaved(NetworkDevice $d): void {}

    /** Prueba de conexión; actualiza estado y último error del equipo. */
    public function testRouter(int $id): void
    {
        $this->authorize('network.manage');
        $d = NetworkDevice::mikrotik()->findOrFail($id);
        try {
            $info = NetworkClient::mikrotik($d)->test();
            $d->forceFill(['status' => 'online', 'last_seen_at' => now(), 'last_error' => null])->saveQuietly();
            $this->toast("Conexión exitosa con {$info['identity']} · RouterOS {$info['version']} · {$info['board']}");
        } catch (\Throwable $e) {
            $msg = NetworkClient::friendlyError($e);
            $d->forceFill(['last_error' => mb_substr($msg, 0, 190)])->saveQuietly();
            $this->toast("No se pudo conectar con {$d->name}: {$msg}", 'error');
        }
    }

    public function regenerateToken(int $id): void
    {
        $this->authorize('network.manage');
        $d = NetworkDevice::mikrotik()->findOrFail($id);
        $d->forceFill(['push_token' => Str::random(40)])->save();
        Audit::log('token', "regeneró el token del agente push de {$d->name}", $d);
        $this->toast('Token regenerado. Vuelve a cargar el script en el MikroTik.', 'warning');
    }

    protected function routerFormOptions(): array
    {
        return ['towerOptions' => Tower::query()->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name])];
    }
}
