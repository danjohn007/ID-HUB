<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tower extends TenantModel
{
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function repeaters(): HasMany
    {
        return $this->hasMany(Repeater::class);
    }

    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }
}
