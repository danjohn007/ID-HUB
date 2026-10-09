<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Ticket;

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

        return [
            'id' => $this->id,
            'sequence' => (int) $this->sequence,
            'status' => $this->status,
            'type' => $this->stoppable_type,
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
