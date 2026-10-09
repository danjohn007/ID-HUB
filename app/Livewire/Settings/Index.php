<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\Toasts;
use App\Models\AutomationRule;
use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Models\Zone;
use App\Support\Audit;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Configuración del WISP (sección 3): empresa, cobranza y cortes, pasarelas de pago,
 * mensajería, red, contrato, comisiones y zonas.
 *
 * Seguridad: las credenciales (pasarelas, tokens de WhatsApp/Twilio) nunca se envían
 * al navegador; se muestran enmascaradas y solo se sobrescriben si se escribe un valor nuevo.
 */
#[Title('Configuración')]
class Index extends Component
{
    use Toasts, WithFileUploads;

    public const TABS = [
        'empresa' => 'Empresa',
        'cobranza' => 'Cobranza y cortes',
        'pasarelas' => 'Pasarelas de pago',
        'mensajeria' => 'Mensajería',
        'red' => 'Red',
        'contrato' => 'Contrato',
        'comisiones' => 'Comisiones',
        'zonas' => 'Zonas',
    ];

    /** Campos de credenciales por pasarela => [clave => [etiqueta, secreto]] */
    public const GATEWAY_FIELDS = [
        'mercadopago' => ['access_token' => ['Access token', true], 'public_key' => ['Public key', false], 'webhook_secret' => ['Webhook secret', true]],
        'stripe' => ['secret_key' => ['Secret key', true], 'publishable_key' => ['Publishable key', false], 'webhook_secret' => ['Webhook signing secret', true]],
        'paypal' => ['client_id' => ['Client ID', false], 'client_secret' => ['Client secret', true]],
        'oxxo' => [],
    ];

    public const RULE_SUSPEND = 'Corte automático por falta de pago';

    public const RULE_RECONNECT = 'Reconexión automática al recibir pago';

    public const MESSAGING_SECRETS = ['whatsapp_token', 'twilio_token'];

    #[Url]
    public string $tab = 'empresa';

    public array $company = [];

    public $logo = null;

    public array $billing = [];

    public array $gateways = [];

    /** Credenciales nuevas tecleadas (vacías = conservar las guardadas). */
    public array $credentials = [];

    public array $messaging = [];

    public array $secrets = ['whatsapp_token' => '', 'twilio_token' => ''];

    public array $network = [];

    public array $bi = [];

    public string $contract = '';

    public array $commissions = [];

    // Zonas
    public bool $showZone = false;

    public ?int $zoneId = null;

    public array $zone = [];

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'empresa';
        }
        $this->loadAll();
    }

    protected function loadAll(): void
    {
        $t = tenant()->fresh();
        $center = $t->setting('map.center');
        $this->company = [
            'name' => $t->name, 'legal_name' => $t->legal_name, 'rfc' => $t->rfc, 'tax_regime' => $t->tax_regime, 'address' => $t->address,
            'phone' => $t->phone, 'whatsapp' => $t->whatsapp, 'email' => $t->email, 'primary_color' => $t->primary_color, 'secondary_color' => $t->secondary_color,
            'domain' => $t->domain, 'subdomain' => $t->subdomain, 'currency' => $t->currency, 'timezone' => $t->timezone,
            'map_lat' => is_array($center) ? (string) $center[0] : '', 'map_lng' => is_array($center) ? (string) $center[1] : '',
        ];
        $this->billing = $t->settingsFor('billing');
        foreach (['auto_suspend', 'auto_reconnect', 'reconnect_requires_full_payment'] as $k) {
            $this->billing[$k] = (bool) $this->billing[$k];
        }
        $this->messaging = collect($t->settingsFor('messaging'))->except(self::MESSAGING_SECRETS)->all();
        $this->messaging['email_enabled'] = (bool) $this->messaging['email_enabled'];
        $this->messaging['default_channels'] = array_values((array) $this->messaging['default_channels']);
        $this->secrets = ['whatsapp_token' => '', 'twilio_token' => ''];
        $this->network = $t->settingsFor('network');
        $this->bi = $t->settingsFor('bi');
        $this->contract = (string) $t->setting('contract.template');
        $this->commissions = $t->settingsFor('commissions');

        $rows = PaymentGateway::query()->get()->keyBy('provider');
        foreach (array_keys(config('wisp.gateways')) as $p) {
            $g = $rows->get($p);
            $this->gateways[$p] = [
                'enabled' => (bool) $g?->enabled, 'sandbox' => $g ? (bool) $g->sandbox : true,
                'fee_percent' => (string) ($g->fee_percent ?? 0), 'fee_fixed' => (string) ($g->fee_fixed ?? 0),
            ];
            $this->credentials[$p] = array_fill_keys(array_keys(self::GATEWAY_FIELDS[$p] ?? []), '');
        }
    }

    // ------------------------------------------------------------ Utilidades

    protected function nullify(array $data): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    protected function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) > 10 ? '••••••••'.substr($value, -4) : '••••••••';
    }

    /** Guarda una sección de settings sin volcar el JSON completo a la bitácora, y registra solo los cambios (enmascarados). */
    protected function saveSection(string $section, string $label, array $values, array $secretKeys = []): void
    {
        $tenant = tenant();
        $before = $tenant->settingsFor($section);
        Audit::$enabled = false;
        try {
            $tenant->putSettings($section, $values);
        } finally {
            Audit::$enabled = true;
        }
        $this->logDiff($label, $before, $values, $secretKeys);
    }

    protected function logDiff(string $label, array $before, array $after, array $secretKeys = []): void
    {
        $old = [];
        $new = [];
        foreach ($after as $k => $v) {
            $prev = $before[$k] ?? null;
            if ($prev == $v && gettype($prev) === gettype($v) || (is_scalar($prev) && is_scalar($v) && (string) $prev === (string) $v)) {
                continue;
            }
            $secret = in_array($k, $secretKeys, true);
            $old[$k] = $secret ? ($prev ? '••••••' : null) : $prev;
            $new[$k] = $secret ? ($v ? '••••••' : null) : $v;
        }
        if ($new) {
            Audit::log('settings', "actualizó la configuración de {$label}", tenant(), $old, $new);
        }
    }

    // ------------------------------------------------------------ Empresa

    public function saveCompany(): void
    {
        $tenant = tenant();
        $id = $tenant->id;
        $data = $this->validate([
            'company.name' => 'required|string|max:150',
            'company.legal_name' => 'nullable|string|max:190',
            'company.rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i'],
            'company.tax_regime' => 'nullable|string|max:120',
            'company.address' => 'nullable|string|max:190',
            'company.phone' => 'nullable|string|max:30',
            'company.whatsapp' => 'nullable|string|max:30',
            'company.email' => 'nullable|email|max:150',
            'company.primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'company.secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'company.domain' => ['nullable', 'string', 'max:150', 'regex:/^(?!-)[a-z0-9.-]+\.[a-z]{2,}$/i', Rule::unique('tenants', 'domain')->ignore($id)],
            'company.subdomain' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', Rule::unique('tenants', 'subdomain')->ignore($id)],
            'company.currency' => ['required', Rule::in(array_keys(config('wisp.currencies')))],
            'company.timezone' => ['required', 'timezone'],
            'company.map_lat' => 'nullable|numeric|between:-90,90',
            'company.map_lng' => 'nullable|numeric|between:-180,180',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ], [
            'company.rfc.regex' => 'El RFC no tiene un formato válido.',
            'company.subdomain.regex' => 'Usa solo minúsculas, números y guiones.',
            'company.domain.regex' => 'Escribe un dominio válido, ej. internet.miwisp.mx',
        ], [
            'company.name' => 'nombre comercial', 'company.email' => 'correo', 'company.primary_color' => 'color primario',
            'company.secondary_color' => 'color secundario', 'company.domain' => 'dominio', 'company.subdomain' => 'subdominio',
            'company.timezone' => 'zona horaria', 'company.currency' => 'moneda', 'logo' => 'logotipo',
        ])['company'];
        $data = $this->nullify($data);
        $data['rfc'] = $data['rfc'] ? mb_strtoupper($data['rfc']) : null;

        $attrs = collect($data)->except(['map_lat', 'map_lng'])->all();
        if (! $tenant->hasFeature('white_label')) {
            unset($attrs['primary_color'], $attrs['secondary_color']);
        }
        if ($this->logo) {
            $old = $tenant->logo_path;
            $attrs['logo_path'] = $this->logo->store('logos/'.$tenant->id, 'public');
            if ($old && $old !== $attrs['logo_path']) {
                Storage::disk('public')->delete($old);
            }
            $this->logo = null;
        }
        $tenant->update($attrs); // Auditable registra valores anterior/nuevo

        if ($data['map_lat'] !== null && $data['map_lng'] !== null) {
            $this->saveSection('map', 'mapa', ['center' => [(float) $data['map_lat'], (float) $data['map_lng']]]);
        }
        $this->toast('Datos de la empresa guardados');
    }

    public function removeLogo(): void
    {
        $tenant = tenant();
        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $tenant->update(['logo_path' => null]);
        }
        $this->toast('Logotipo eliminado');
    }

    // ------------------------------------------------------------ Cobranza

    public function saveBilling(): void
    {
        $data = $this->validate([
            'billing.mode' => 'required|in:fixed_day,anniversary',
            'billing.billing_day' => 'required|integer|between:1,28',
            'billing.due_days' => 'required|integer|between:0,30',
            'billing.grace_days' => 'required|integer|between:0,60',
            'billing.cut_hour' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'billing.reminder_days_before' => 'required|integer|between:0,15',
            'billing.late_fee' => 'required|numeric|min:0|max:100000',
            'billing.reconnection_fee' => 'required|numeric|min:0|max:100000',
            'billing.block_type' => 'required|in:partial,total',
            'billing.auto_suspend' => 'boolean',
            'billing.auto_reconnect' => 'boolean',
            'billing.reconnect_requires_full_payment' => 'boolean',
            'billing.folio_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/i'],
        ], [], [
            'billing.billing_day' => 'día de corte', 'billing.due_days' => 'días para pagar', 'billing.grace_days' => 'días de tolerancia',
            'billing.cut_hour' => 'hora de corte', 'billing.reminder_days_before' => 'días de recordatorio', 'billing.late_fee' => 'recargo',
            'billing.reconnection_fee' => 'cargo de reconexión', 'billing.folio_prefix' => 'prefijo de folio',
        ])['billing'];

        $values = [
            'mode' => $data['mode'], 'billing_day' => (int) $data['billing_day'], 'due_days' => (int) $data['due_days'],
            'grace_days' => (int) $data['grace_days'], 'cut_hour' => $data['cut_hour'], 'reminder_days_before' => (int) $data['reminder_days_before'],
            'late_fee' => (float) $data['late_fee'], 'reconnection_fee' => (float) $data['reconnection_fee'], 'block_type' => $data['block_type'],
            'auto_suspend' => (bool) $data['auto_suspend'], 'auto_reconnect' => (bool) $data['auto_reconnect'],
            'reconnect_requires_full_payment' => (bool) $data['reconnect_requires_full_payment'], 'folio_prefix' => mb_strtoupper($data['folio_prefix']),
        ];
        $this->saveSection('billing', 'cobranza y cortes', $values);

        // Las reglas del sistema siguen a los interruptores de corte / reconexión automáticos
        foreach ([self::RULE_SUSPEND => $values['auto_suspend'], self::RULE_RECONNECT => $values['auto_reconnect']] as $name => $active) {
            AutomationRule::query()->where('name', $name)->get()->each(fn ($r) => $r->active === $active ?: $r->update(['active' => $active]));
        }
        $this->toast('Políticas de cobranza y cortes guardadas');
    }

    // ------------------------------------------------------------ Pasarelas

    public function saveGateway(string $provider): void
    {
        abort_unless(array_key_exists($provider, config('wisp.gateways')), 404);
        $fields = self::GATEWAY_FIELDS[$provider] ?? [];
        $rules = [
            "gateways.$provider.enabled" => 'boolean',
            "gateways.$provider.sandbox" => 'boolean',
            "gateways.$provider.fee_percent" => 'required|numeric|min:0|max:20',
            "gateways.$provider.fee_fixed" => 'required|numeric|min:0|max:1000',
        ];
        foreach ($fields as $k => $f) {
            $rules["credentials.$provider.$k"] = 'nullable|string|max:500';
        }
        $this->validate($rules, [], [
            "gateways.$provider.fee_percent" => 'comisión %', "gateways.$provider.fee_fixed" => 'comisión fija',
        ]);

        $gateway = PaymentGateway::query()->firstOrNew(['provider' => $provider]);
        $creds = $gateway->credentials ?? [];
        $typed = [];
        foreach ($fields as $k => $f) {
            $v = trim((string) ($this->credentials[$provider][$k] ?? ''));
            if ($v !== '') {
                $creds[$k] = $v;
                $typed[$k] = $f[1] ? '••••••' : $v;
            }
        }
        $g = $this->gateways[$provider];
        if ($g['enabled']) {
            $missing = $provider === 'oxxo'
                ? (PaymentGateway::query()->where('provider', 'stripe')->first()?->credential('secret_key') ? [] : ['secret_key de Stripe'])
                : collect($fields)->filter(fn ($f, $k) => $k !== 'webhook_secret' && empty($creds[$k]))->map(fn ($f) => $f[0])->values()->all();
            if ($missing) {
                $this->addError("gateways.$provider.enabled", 'Para habilitarla captura: '.implode(', ', $missing).'.');

                return;
            }
        }
        $before = $gateway->exists ? ['enabled' => (bool) $gateway->enabled, 'sandbox' => (bool) $gateway->sandbox, 'fee_percent' => (float) $gateway->fee_percent, 'fee_fixed' => (float) $gateway->fee_fixed] : [];
        $after = ['enabled' => (bool) $g['enabled'], 'sandbox' => (bool) $g['sandbox'], 'fee_percent' => (float) $g['fee_percent'], 'fee_fixed' => (float) $g['fee_fixed']];
        $gateway->fill($after + ['credentials' => $creds ?: null])->save();

        $this->logDiff('la pasarela '.config("wisp.gateways.$provider"), $before, $after + array_map(fn () => '••••••', $typed));
        $this->credentials[$provider] = array_fill_keys(array_keys($fields), '');
        $this->toast(config("wisp.gateways.$provider").' guardada');
    }

    public function clearCredentials(string $provider): void
    {
        abort_unless(array_key_exists($provider, config('wisp.gateways')), 404);
        $gateway = PaymentGateway::query()->where('provider', $provider)->first();
        if ($gateway) {
            $gateway->update(['credentials' => null, 'enabled' => false]);
            $this->gateways[$provider]['enabled'] = false;
            Audit::log('settings', 'eliminó las credenciales de '.config("wisp.gateways.$provider"), tenant());
        }
        $this->toast('Credenciales eliminadas; la pasarela quedó deshabilitada', 'warning');
    }

    // ------------------------------------------------------------ Mensajería

    public function saveMessaging(): void
    {
        $data = $this->validate([
            'messaging.whatsapp_provider' => 'required|in:simulated,meta,twilio',
            'messaging.whatsapp_phone_id' => 'nullable|string|max:80',
            'messaging.sms_provider' => 'required|in:simulated,twilio',
            'messaging.twilio_sid' => 'nullable|string|max:80',
            'messaging.twilio_from' => 'nullable|string|max:30',
            'messaging.twilio_whatsapp_from' => 'nullable|string|max:30',
            'messaging.email_enabled' => 'boolean',
            'messaging.push_provider' => 'required|in:simulated,expo',
            'messaging.default_channels' => 'array|min:1',
            'messaging.default_channels.*' => ['string', Rule::in(array_keys(config('wisp.channels')))],
            'secrets.whatsapp_token' => 'nullable|string|max:1000',
            'secrets.twilio_token' => 'nullable|string|max:200',
        ], ['messaging.default_channels.min' => 'Selecciona al menos un canal.'], [
            'messaging.whatsapp_phone_id' => 'Phone number ID', 'messaging.twilio_sid' => 'Account SID',
        ]);
        $m = $data['messaging'];
        $current = tenant()->settingsFor('messaging');
        $token = trim((string) $this->secrets['whatsapp_token']) ?: $current['whatsapp_token'];
        $twilioToken = trim((string) $this->secrets['twilio_token']) ?: $current['twilio_token'];

        $usesTwilio = $m['whatsapp_provider'] === 'twilio' || $m['sms_provider'] === 'twilio';
        $errors = [];
        if ($m['whatsapp_provider'] === 'meta') {
            empty($m['whatsapp_phone_id']) && $errors['messaging.whatsapp_phone_id'] = 'Requerido para WhatsApp Cloud API (Meta).';
            empty($token) && $errors['secrets.whatsapp_token'] = 'Captura el token de acceso de Meta.';
        }
        if ($usesTwilio) {
            empty($m['twilio_sid']) && $errors['messaging.twilio_sid'] = 'Requerido para Twilio.';
            empty($twilioToken) && $errors['secrets.twilio_token'] = 'Captura el Auth token de Twilio.';
            $m['sms_provider'] === 'twilio' && empty($m['twilio_from']) && $errors['messaging.twilio_from'] = 'Número remitente de SMS requerido.';
            $m['whatsapp_provider'] === 'twilio' && empty($m['twilio_whatsapp_from']) && $errors['messaging.twilio_whatsapp_from'] = 'Número de WhatsApp de Twilio requerido.';
        }
        foreach ($errors as $k => $msg) {
            $this->addError($k, $msg);
        }
        if ($errors) {
            return;
        }

        $values = $this->nullify(collect($m)->except('default_channels')->all()) + [
            'default_channels' => array_values($m['default_channels']),
            'whatsapp_token' => $token ?: null,
            'twilio_token' => $twilioToken ?: null,
        ];
        $values['email_enabled'] = (bool) $m['email_enabled'];
        $this->saveSection('messaging', 'mensajería', $values, self::MESSAGING_SECRETS);
        $this->secrets = ['whatsapp_token' => '', 'twilio_token' => ''];
        $this->toast('Configuración de mensajería guardada');
    }

    // ------------------------------------------------------------ Red / BI

    public function saveNetwork(): void
    {
        $d = $this->validate([
            'network.signal_min_dbm' => 'required|numeric|between:-100,-30',
            'network.latency_max_ms' => 'required|integer|between:1,5000',
            'network.packet_loss_max' => 'required|numeric|between:0,100',
            'network.mass_disconnect_threshold' => 'required|integer|between:1,10000',
            'network.usage_anomaly_factor' => 'required|numeric|between:1,50',
            'bi.repeater_cost_estimate' => 'required|numeric|min:0|max:10000000',
            'bi.sector_cost_estimate' => 'required|numeric|min:0|max:10000000',
            'bi.expected_conversion' => 'required|numeric|between:1,100',
            'bi.cluster_km' => 'required|numeric|between:0.3,5',
        ], [], [
            'network.signal_min_dbm' => 'señal mínima', 'network.latency_max_ms' => 'latencia máxima', 'network.packet_loss_max' => 'pérdida máxima',
            'network.mass_disconnect_threshold' => 'umbral de desconexión masiva', 'network.usage_anomaly_factor' => 'factor de consumo anormal',
            'bi.repeater_cost_estimate' => 'costo de repetidor', 'bi.sector_cost_estimate' => 'costo de ampliación', 'bi.expected_conversion' => 'conversión esperada',
            'bi.cluster_km' => 'radio de agrupación',
        ]);
        $this->saveSection('network', 'umbrales de red', array_map(fn ($v) => $v + 0, $d['network']));
        $this->saveSection('bi', 'parámetros de expansión (BI)', array_map(fn ($v) => $v + 0, $d['bi']));
        $this->toast('Umbrales de red y parámetros de BI guardados');
    }

    // ------------------------------------------------------------ Contrato

    public const CONTRACT_VARS = ['empresa', 'cliente', 'direccion', 'plan', 'velocidad', 'periodicidad', 'precio', 'tolerancia', 'fecha'];

    public function saveContract(): void
    {
        $this->validate(['contract' => 'required|string|min:50|max:20000'], [], ['contract' => 'plantilla del contrato']);
        $this->saveSection('contract', 'contrato', ['template' => $this->contract]);
        $this->toast('Plantilla de contrato guardada');
    }

    public function restoreContract(): void
    {
        $this->contract = (string) config('wisp.defaults.contract.template');
        $this->toast('Se cargó la plantilla original. Guarda para aplicarla.', 'info');
    }

    // ------------------------------------------------------------ Comisiones

    public function saveCommissions(): void
    {
        $d = $this->validate([
            'commissions.collector_percent' => 'required|numeric|between:0,100',
            'commissions.technician_install_fee' => 'required|numeric|min:0|max:100000',
        ], [], ['commissions.collector_percent' => 'comisión del cobrador', 'commissions.technician_install_fee' => 'pago por instalación'])['commissions'];
        $this->saveSection('commissions', 'comisiones', array_map(fn ($v) => $v + 0, $d));
        $this->toast('Comisiones guardadas');
    }

    // ------------------------------------------------------------ Zonas

    public function newZone(): void
    {
        $this->resetErrorBag();
        $this->zoneId = null;
        $center = tenant()->setting('map.center');
        $this->zone = ['name' => '', 'community' => '', 'color' => '#1A7FF5', 'lat' => is_array($center) ? (string) $center[0] : '', 'lng' => is_array($center) ? (string) $center[1] : '', 'monthly_cost' => '0'];
        $this->showZone = true;
    }

    public function editZone(int $id): void
    {
        $this->resetErrorBag();
        $z = Zone::findOrFail($id);
        $this->zoneId = $z->id;
        $this->zone = ['name' => $z->name, 'community' => $z->community, 'color' => $z->color, 'lat' => (string) $z->lat, 'lng' => (string) $z->lng, 'monthly_cost' => (string) $z->monthly_cost];
        $this->showZone = true;
    }

    public function saveZone(): void
    {
        $tid = tenant()->id;
        $d = $this->validate([
            'zone.name' => ['required', 'string', 'max:120', Rule::unique('zones', 'name')->where('tenant_id', $tid)->ignore($this->zoneId)],
            'zone.community' => 'nullable|string|max:120',
            'zone.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'zone.lat' => 'nullable|numeric|between:-90,90',
            'zone.lng' => 'nullable|numeric|between:-180,180',
            'zone.monthly_cost' => 'required|numeric|min:0|max:10000000',
        ], [], ['zone.name' => 'nombre', 'zone.monthly_cost' => 'costo mensual', 'zone.lat' => 'latitud', 'zone.lng' => 'longitud'])['zone'];
        $d = $this->nullify($d);

        if ($this->zoneId) {
            $z = Zone::findOrFail($this->zoneId);
            $old = $z->only(array_keys($d));
            $z->update($d);
            $this->logDiff('la zona '.$z->name, $old, $z->only(array_keys($d)));
        } else {
            $z = Zone::create($d);
            Audit::log('created', "creó la zona {$z->name}", $z, [], $d);
        }
        $this->showZone = false;
        $this->toast('Zona guardada');
    }

    public function deleteZone(int $id): void
    {
        $z = Zone::query()->withCount(['clients', 'repeaters'])->findOrFail($id);
        if ($z->clients_count || $z->repeaters_count) {
            $this->toast("No se puede eliminar: la zona tiene {$z->clients_count} clientes y {$z->repeaters_count} repetidores.", 'error');

            return;
        }
        Audit::log('deleted', "eliminó la zona {$z->name}", $z, $z->only(['name', 'community', 'monthly_cost']));
        $z->delete();
        $this->toast('Zona eliminada');
    }

    // ------------------------------------------------------------ Render

    public function render()
    {
        $tenant = tenant()->fresh(['saasPlan']);
        $data = ['tenant' => $tenant, 'whiteLabel' => $tenant->hasFeature('white_label')];

        if ($this->tab === 'pasarelas') {
            $rows = PaymentGateway::query()->get()->keyBy('provider');
            $data['masked'] = collect(self::GATEWAY_FIELDS)->map(fn ($fields, $p) => collect($fields)->map(fn ($f, $k) => $this->mask($rows->get($p)?->credential($k)))->all())->all();
            $data['hasCredentials'] = $rows->map(fn ($g) => ! empty($g->credentials))->all();
        }
        if ($this->tab === 'mensajeria') {
            $m = $tenant->settingsFor('messaging');
            $data['maskedSecrets'] = ['whatsapp_token' => $this->mask($m['whatsapp_token'] ?? null), 'twilio_token' => $this->mask($m['twilio_token'] ?? null)];
        }
        if ($this->tab === 'cobranza') {
            $data['rules'] = AutomationRule::query()->whereIn('name', [self::RULE_SUSPEND, self::RULE_RECONNECT])->pluck('active', 'name');
        }
        if ($this->tab === 'contrato') {
            $data['contractPreview'] = strtr($this->contract, [
                '{empresa}' => $tenant->legal_name ?: $tenant->name, '{cliente}' => 'Juan Pérez López', '{direccion}' => 'Calle Hidalgo 12, Col. Centro',
                '{plan}' => 'Hogar', '{velocidad}' => '20 Mbps', '{periodicidad}' => 'mensual', '{precio}' => money(350),
                '{tolerancia}' => (string) $tenant->setting('billing.grace_days'), '{fecha}' => now()->translatedFormat('d \d\e F \d\e Y'),
            ]);
        }
        if ($this->tab === 'zonas') {
            $data['zones'] = Zone::query()->withCount([
                'clients' => fn ($q) => $q->whereNotIn('service_status', ['cancelado']), 'repeaters', 'towers',
            ])->orderBy('name')->get();
        }

        return view('livewire.settings.index', $data);
    }
}
