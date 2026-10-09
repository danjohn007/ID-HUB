<?php

namespace App\Http\Resources;

class AttachmentResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => config("wisp.evidence_kinds.{$this->kind}", $this->kind),
            'url' => $this->url(),
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'is_image' => $this->isImage(),
            'location' => self::coords($this->lat, $this->lng),
            'taken_at' => self::datetime($this->taken_at),
            'created_at' => self::datetime($this->created_at),
        ];
    }
}
