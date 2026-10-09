<?php

namespace App\Http\Resources;

class TicketResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'subject' => $this->subject,
            'description' => $this->description,
            'category' => ['value' => $this->category, 'label' => config("wisp.ticket_categories.{$this->category}", $this->category)],
            'priority' => self::catalog('ticket_priorities', $this->priority),
            'status' => self::catalog('ticket_status', $this->status),
            'source' => $this->source,
            'client' => $this->whenLoaded('client', fn () => $this->client ? new ClientSummaryResource($this->client) : null),
            'technician' => $this->whenLoaded('technician', fn () => $this->technician ? new UserSummaryResource($this->technician) : null),
            'repeater_id' => $this->repeater_id,
            'sla' => ['due_at' => self::datetime($this->sla_due_at), 'state' => $this->slaState(), 'breached' => (bool) $this->sla_breached],
            'arrived_at' => self::datetime($this->arrived_at),
            'departed_at' => self::datetime($this->departed_at),
            'resolved_at' => self::datetime($this->resolved_at),
            'closed_at' => self::datetime($this->closed_at),
            'resolution' => $this->resolution,
            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => self::datetime($this->created_at),
            'updated_at' => self::datetime($this->updated_at),
        ];
    }
}
