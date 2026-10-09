<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends TenantModel
{
    protected $casts = ['data' => 'array', 'resolved_at' => 'datetime'];

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['open', 'acknowledged']);
    }

    /** URL del recurso relacionado (para el botón "Ver"). */
    public function subjectUrl(): ?string
    {
        $map = [
            'client' => 'clients.show',
            'repeater' => 'network.repeaters.show',
            'device' => 'network.devices.show',
            'ticket' => 'tickets.show',
            'inventory_item' => 'inventory.index',
            'zone' => 'map.index',
        ];
        $route = $map[$this->subject_type] ?? null;
        if (! $route || ! \Illuminate\Support\Facades\Route::has($route)) {
            return null;
        }

        return in_array($this->subject_type, ['inventory_item', 'zone'])
            ? route($route)
            : route($route, $this->subject_id);
    }
}
