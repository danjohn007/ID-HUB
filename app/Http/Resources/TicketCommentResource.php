<?php

namespace App\Http\Resources;

class TicketCommentResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'internal' => (bool) $this->internal,
            'author' => ($this->meta['by'] ?? null) === 'client' ? 'Cliente' : ($this->user?->name ?? 'Sistema'),
            'created_at' => self::datetime($this->created_at),
        ];
    }
}
