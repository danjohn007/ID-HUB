<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Para obtener utilización sin N+1 usar Repeater::withLoad()->get()
 * (agrega active_clients_count).
 */
class Repeater extends TenantModel
{
    use Auditable;

    protected $auditExclude = ['last_seen_at', 'status', 'down_since'];

    protected $casts = ['last_seen_at' => 'datetime', 'down_since' => 'datetime'];

    public function tower(): BelongsTo
    {
        return $this->belongsTo(Tower::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'router_device_id');
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'ap_device_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }

    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }

    public function outages(): HasMany
    {
        return $this->hasMany(Outage::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(MonitoringSample::class, 'subject_id')->where('subject_type', 'repeater');
    }

    public function activeOutage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Outage::class)->whereNull('ended_at')->latestOfMany('started_at');
    }

    public function scopeWithLoad(Builder $q): Builder
    {
        return $q->withCount([
            'clients as active_clients_count' => fn ($c) => $c->whereNotIn('service_status', ['cancelado', 'prospecto']),
            'clients as online_clients_count' => fn ($c) => $c->where('online', true)->whereNotIn('service_status', ['cancelado', 'prospecto']),
        ]);
    }

    public function clientLoad(): int
    {
        return (int) ($this->getAttribute('active_clients_count')
            ?? $this->clients()->whereNotIn('service_status', ['cancelado', 'prospecto'])->count());
    }

    public function utilization(): float
    {
        return $this->capacity > 0 ? round($this->clientLoad() / $this->capacity * 100, 1) : 0;
    }

    public function utilizationColor(): string
    {
        $u = $this->utilization();

        return $u >= 95 ? 'danger' : ($u >= $this->alert_threshold ? 'warning' : 'success');
    }

    public function auditLabel(): string
    {
        return 'repetidor '.$this->code;
    }
}
