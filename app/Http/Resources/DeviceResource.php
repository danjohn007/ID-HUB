<?php

namespace App\Http\Resources;

/** Equipo de red (no expone credenciales de API). */
class DeviceResource extends ApiResource
{
    public function toArray($request): array
    {
        $num = fn ($v) => $v !== null ? (float) $v : null;

        return [
            'id' => $this->id,
            'type' => ['value' => $this->type, 'label' => config("wisp.device_types.{$this->type}", $this->type)],
            'name' => $this->name,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial' => $this->serial,
            'mac' => $this->mac,
            'ip' => $this->ip,
            'firmware' => $this->firmware,
            'location' => $this->location,
            'coordinates' => self::coords($this->lat, $this->lng),
            'repeater_id' => $this->repeater_id,
            'tower_id' => $this->tower_id,
            'client_id' => $this->client_id,
            'status' => self::catalog('device_status', $this->status),
            'metrics' => [
                'cpu' => $num($this->cpu),
                'memory' => $num($this->memory),
                'latency_ms' => $num($this->latency_ms),
                'packet_loss' => $num($this->packet_loss),
                'signal_dbm' => $num($this->signal_dbm),
                'uptime' => $this->uptime,
            ],
            'last_seen_at' => self::datetime($this->last_seen_at),
            'last_error' => $this->last_error,
        ];
    }
}
