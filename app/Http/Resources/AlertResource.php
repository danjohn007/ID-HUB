<?php

namespace App\Http\Resources;

class AlertResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => ['value' => $this->type, 'label' => config("wisp.alert_types.{$this->type}", $this->type)],
            'severity' => self::catalog('alert_severity', $this->severity),
            'title' => $this->title,
            'message' => $this->message,
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id] : null,
            'status' => $this->status,
            'resolved_at' => self::datetime($this->resolved_at),
            'created_at' => self::datetime($this->created_at),
        ];
    }
}
