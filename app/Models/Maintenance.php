<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Maintenance extends TenantModel
{
    use Auditable;

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'notified_at' => 'datetime',
        'device_ids' => 'array',
    ];

    public const STATUS = [
        'scheduled' => ['Programado', 'info'],
        'in_progress' => ['En curso', 'warning'],
        'completed' => ['Completado', 'success'],
        'cancelled' => ['Cancelado', 'slate'],
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }

    /** Clientes potencialmente afectados (por repetidor, zona o equipos). */
    public function affectedClientsQuery()
    {
        return Client::query()->billable()->where(function ($q) {
            $any = false;
            if ($this->repeater_id) {
                $q->orWhere('repeater_id', $this->repeater_id);
                $any = true;
            }
            if ($this->zone_id) {
                $q->orWhere('zone_id', $this->zone_id);
                $any = true;
            }
            if ($this->device_ids) {
                $q->orWhereIn('router_device_id', $this->device_ids)->orWhereIn('cpe_device_id', $this->device_ids);
                $any = true;
            }
            if (! $any) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function auditLabel(): string
    {
        return 'mantenimiento '.$this->title;
    }
}
