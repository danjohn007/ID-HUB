<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sector extends TenantModel
{
    public function tower(): BelongsTo
    {
        return $this->belongsTo(Tower::class);
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }
}
