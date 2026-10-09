<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RouteStop extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['arrived_at' => 'datetime', 'departed_at' => 'datetime'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(FieldRoute::class, 'field_route_id');
    }

    /** ticket, installation_order o client (cobranza). */
    public function stoppable(): MorphTo
    {
        return $this->morphTo();
    }

    public function navigationUrl(): ?string
    {
        return $this->lat && $this->lng
            ? 'https://www.google.com/maps/dir/?api=1&destination='.$this->lat.','.$this->lng
            : null;
    }
}
