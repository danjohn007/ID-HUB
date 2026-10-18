<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Ticket;
use App\Services\Field\FieldPlanner;

class RouteStopResource extends ApiResource
{
    public function toArray($request): array
    {
        $s = $this->stoppable;
        $client = match (true) {
            $s instanceof Client => $s,
            $s instanceof Ticket, $s instanceof InstallationOrder => $s->client,
            default => null,
        };

        // Mismo "punto" que pinta la app de campo web (título, dirección, prioridad, SLA)
        $point = $s ? app(FieldPlanner::class)->stopPoint($this->resource) : null;

        return [
            'id' => $this->id,
            'key' => $this->stoppable_type.':'.$this->stoppable_id,
            'ref_id' => (int) $this->stoppable_id,
            'sequence' => (int) $this->sequence,
            'status' => $this->status,
            'status_label' => self::catalog('route_stop_status', $this->status),
            'type' => $this->stoppable_type,
            'title' => $point['title'] ?? null,
            'subtitle' => $point['subtitle'] ?? null,
            'address' => $point['address'] ?? null,
            'phone' => $point['phone'] ?? null,
            'priority' => $point['priority'] ?? null,
            'badge' => isset($point['badge']) ? ['label' => $point['badge'][0], 'color' => $point['badge'][1]] : null,
            'sla' => $point['sla'] ?? null,
            'subject' => match (true) {
                $s instanceof Ticket => ['id' => $s->id, 'folio' => $s->folio, 'subject' => $s->subject, 'status' => $s->status, 'priority' => $s->priority],
                $s instanceof InstallationOrder => ['id' => $s->id, 'folio' => $s->folio, 'status' => $s->status],
                $s instanceof Client => ['id' => $s->id, 'code' => $s->code, 'balance' => self::money($s->balance)],
                default => null,
            },
            'client' => $client ? new ClientSummaryResource($client) : null,
            'location' => self::coords($this->lat, $this->lng),
            'navigation_url' => $this->navigationUrl(),
            'arrived_at' => self::datetime($this->arrived_at),
            'departed_at' => self::datetime($this->departed_at),
        ];
    }
}
