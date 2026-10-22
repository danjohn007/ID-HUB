<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Tenant extends Model
{
    use Auditable, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'date',
        'subscription_ends_at' => 'date',
    ];

    public function saasPlan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }

    public function saasInvoices(): HasMany
    {
        return $this->hasMany(SaasInvoice::class);
    }

    /** Valor de configuración con fallback a config('wisp.defaults'). */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = Arr::get($this->settings ?? [], $key);

        return $value ?? Arr::get(config('wisp.defaults'), $key, $default);
    }

    /** Configuración completa de una sección fusionada con defaults. */
    public function settingsFor(string $section): array
    {
        return array_merge(config("wisp.defaults.$section", []), $this->settings[$section] ?? []);
    }

    public function putSettings(string $section, array $values): void
    {
        $settings = $this->settings ?? [];
        $settings[$section] = array_merge($settings[$section] ?? [], $values);
        $this->settings = $settings;
        $this->save();
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trial'], true);
    }

    /**
     * WISP al que apunta el dominio de la petición: su dominio propio (portal.miwisp.com) o su
     * subdominio del dominio raíz de la plataforma (miwisp.<TENANT_ROOT_DOMAIN>). Solo WISP activos.
     */
    public static function forHost(?string $host): ?self
    {
        $host = strtolower(trim((string) $host));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $root = strtolower(trim((string) config('wisp.tenant_root_domain'), '. '));
        $sub = $root !== '' && str_ends_with($host, '.'.$root) ? substr($host, 0, -strlen($root) - 1) : null;

        $tenant = static::query()->where('domain', $host)
            ->when($sub && ! str_contains($sub, '.') && $sub !== 'www', fn ($q) => $q->orWhere('subdomain', $sub))
            ->first();

        return $tenant?->isActive() ? $tenant : null;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function hasFeature(string $feature): bool
    {
        $features = $this->saasPlan?->features ?? [];

        return ! $this->saasPlan || in_array($feature, $features, true) || in_array('*', $features, true);
    }

    public function auditLabel(): string
    {
        return 'WISP '.$this->name;
    }

    /*
    |--------------------------------------------------------------------------
    | Límites del plan SaaS (sección 48)
    |--------------------------------------------------------------------------
    | Recursos: clients (clientes no cancelados), devices (equipos MikroTik en
    | operación), users (usuarios activos). null = ilimitado.
    |   if (! tenant()->withinLimit('clients')) { ... }
    */
    public const LIMIT_RESOURCES = [
        'clients' => ['max_clients', 'clientes'],
        'devices' => ['max_devices', 'equipos MikroTik'],
        'users' => ['max_users', 'usuarios'],
    ];

    public function planLimit(string $resource): ?int
    {
        $column = self::LIMIT_RESOURCES[$resource][0] ?? null;
        $value = $column ? $this->saasPlan?->{$column} : null;

        return $value === null ? null : (int) $value;
    }

    public function usage(string $resource): int
    {
        return ($q = self::usageQuery($resource)) ? $q->where('tenant_id', $this->id)->count() : 0;
    }

    /** Uso de un recurso por WISP en una sola consulta (panel SaaS): [tenant_id => n] */
    public static function usageByTenant(string $resource): Collection
    {
        return ($q = self::usageQuery($resource))
            ? $q->selectRaw('tenant_id, COUNT(*) n')->groupBy('tenant_id')->pluck('n', 'tenant_id')
            : collect();
    }

    /** Estados que NO cuentan contra el límite. */
    public const UNCOUNTED_CLIENT_STATUSES = ['cancelado', 'prospecto'];

    public const UNCOUNTED_DEVICE_STATUSES = ['retired', 'in_stock'];

    /** Registros que cuentan contra el límite del plan (sin filtrar por WISP). */
    protected static function usageQuery(string $resource): ?Builder
    {
        return match ($resource) {
            'clients' => DB::table('clients')->whereNull('deleted_at')->whereNotIn('service_status', self::UNCOUNTED_CLIENT_STATUSES),
            'devices' => DB::table('network_devices')->whereNull('deleted_at')->where('type', 'mikrotik')->whereNotIn('status', self::UNCOUNTED_DEVICE_STATUSES),
            'users' => DB::table('users')->where('active', true),
            default => null,
        };
    }

    /** ¿Un registro con estos atributos cuenta contra el límite? (mismos criterios que usageQuery) */
    public static function countsTowardLimit(string $resource, array $attrs): bool
    {
        return match ($resource) {
            'clients' => ! in_array($attrs['service_status'] ?? null, self::UNCOUNTED_CLIENT_STATUSES, true),
            'devices' => ($attrs['type'] ?? null) === 'mikrotik' && ! in_array($attrs['status'] ?? null, self::UNCOUNTED_DEVICE_STATUSES, true),
            'users' => (bool) ($attrs['active'] ?? false),
            default => false,
        };
    }

    /**
     * ¿El alta/cambio rebasa el plan? Solo bloquea cuando el registro EMPIEZA a contar
     * ($before null = alta) y ya no hay cupo; lo existente sigue operando aunque el WISP
     * esté por encima del límite.
     *   if (tenant()->exceedsLimit('clients', $data, $client?->only('service_status'))) { ... }
     */
    public function exceedsLimit(string $resource, array $after, ?array $before = null): bool
    {
        if (! self::countsTowardLimit($resource, $after) || ($before !== null && self::countsTowardLimit($resource, $before))) {
            return false;
        }

        return ! $this->withinLimit($resource);
    }

    /** ¿Se pueden agregar $adding unidades del recurso sin exceder el plan? */
    public function withinLimit(string $resource, int $adding = 1): bool
    {
        $limit = $this->planLimit($resource);

        return $limit === null || $this->usage($resource) + $adding <= $limit;
    }

    /** Mensaje estándar al alcanzar un límite. */
    public function limitMessage(string $resource): string
    {
        $label = self::LIMIT_RESOURCES[$resource][1] ?? $resource;

        return "Tu plan {$this->saasPlan?->name} permite hasta ".number_format((int) $this->planLimit($resource))." {$label}. Contacta a ID-Hub para ampliar tu plan.";
    }
}
