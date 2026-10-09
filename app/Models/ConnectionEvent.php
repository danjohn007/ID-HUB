<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de conexión detectado por el monitoreo de red (desconexión, cambio de MAC...).
 */
class ConnectionEvent extends TenantModel
{
    public $timestamps = false;

    protected $casts = ['occurred_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }
}
