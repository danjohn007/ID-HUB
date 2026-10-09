<?php

namespace App\Http\Resources;

class RepeaterResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->location,
            'coordinates' => self::coords($this->lat, $this->lng),
            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->name] : null),
            'status' => self::catalog('device_status', $this->status),
            'capacity' => (int) $this->capacity,
            'active_clients' => $this->clientLoad(),
            'online_clients' => $this->getAttribute('online_clients_count'),
            'utilization_pct' => $this->utilization(),
            'utilization_color' => $this->utilizationColor(),
            'coverage_radius_km' => (float) $this->coverage_radius_km,
            'bandwidth_mbps' => $this->bandwidth_mbps !== null ? (float) $this->bandwidth_mbps : null,
            'last_seen_at' => self::datetime($this->last_seen_at),
            'down_since' => self::datetime($this->down_since),
        ];
    }
}
