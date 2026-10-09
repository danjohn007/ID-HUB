<?php

namespace App\Http\Resources;

class FieldRouteResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'date' => self::date($this->date),
            'status' => $this->status,
            'zone' => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->name] : null,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'maps_url' => $this->mapsUrl(),
            'stops_total' => $this->stops->count(),
            'stops_done' => $this->stops->where('status', 'done')->count(),
            'stops' => RouteStopResource::collection($this->stops),
        ];
    }
}
