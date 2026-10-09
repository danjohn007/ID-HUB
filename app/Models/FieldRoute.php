<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldRoute extends TenantModel
{
    protected $casts = ['date' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('sequence');
    }

    /** URL de Google Maps con la ruta completa (máx. 10 puntos por limitación de Maps). */
    public function mapsUrl(): ?string
    {
        $points = $this->stops->filter(fn ($s) => $s->lat && $s->lng)->take(10)->map(fn ($s) => $s->lat.','.$s->lng)->values();
        if ($points->isEmpty()) {
            return null;
        }
        $destination = $points->pop();
        $url = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='.$destination;
        if ($points->isNotEmpty()) {
            $url .= '&waypoints='.urlencode($points->implode('|'));
        }

        return $url;
    }
}
