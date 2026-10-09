<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends TenantModel
{
    protected $casts = ['polygon' => 'array'];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function repeaters(): HasMany
    {
        return $this->hasMany(Repeater::class);
    }

    public function towers(): HasMany
    {
        return $this->hasMany(Tower::class);
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }
}
