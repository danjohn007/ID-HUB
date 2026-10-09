<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Outage extends TenantModel
{
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'last_communication_at' => 'datetime'];

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'device_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
