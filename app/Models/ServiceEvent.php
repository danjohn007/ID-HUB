<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceEvent extends TenantModel
{
    protected $casts = ['data' => 'array', 'network_applied' => 'boolean'];

    public const TYPES = [
        'activation' => ['Activación', 'success'],
        'suspension' => ['Suspensión', 'danger'],
        'reconnection' => ['Reconexión', 'success'],
        'plan_change' => ['Cambio de plan', 'info'],
        'speed_change' => ['Cambio de velocidad', 'info'],
        'cancellation' => ['Cancelación', 'slate'],
        'repeater_change' => ['Cambio de repetidor', 'purple'],
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
