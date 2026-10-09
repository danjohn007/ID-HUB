<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class PromotionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'value' => (float) $this->value,
            'value_label' => $this->valueLabel(),
            'code' => $this->code,
            'starts_at' => self::date($this->starts_at),
            'ends_at' => self::date($this->ends_at),
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        ];
    }
}
