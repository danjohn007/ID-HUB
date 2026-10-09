<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NetworkDevice extends TenantModel
{
    use Auditable, SoftDeletes;

    protected $hidden = ['api_password'];

    protected $auditExclude = ['cpu', 'memory', 'latency_ms', 'packet_loss', 'signal_dbm', 'uptime', 'last_seen_at', 'last_sync_at', 'last_error', 'status', 'push_token'];

    protected $casts = [
        'api_password' => 'encrypted',
        'api_ssl' => 'boolean',
        'installed_at' => 'date',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function tower(): BelongsTo
    {
        return $this->belongsTo(Tower::class);
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'parent_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** Clientes servidos por este MikroTik. */
    public function routedClients(): HasMany
    {
        return $this->hasMany(Client::class, 'router_device_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(MonitoringSample::class, 'subject_id')->where('subject_type', 'device');
    }

    /** Clientes que usan este equipo como antena / CPE. */
    public function cpeClients(): HasMany
    {
        return $this->hasMany(Client::class, 'cpe_device_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NetworkDevice::class, 'parent_id');
    }

    public function scopeMikrotik(Builder $q): Builder
    {
        return $q->where('type', 'mikrotik');
    }

    public function isMikrotik(): bool
    {
        return $this->type === 'mikrotik';
    }

    public function auditLabel(): string
    {
        return 'equipo '.$this->name;
    }
}
