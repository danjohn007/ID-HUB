<?php

namespace App\Http\Resources;

class UserResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            'tenant_id' => $this->tenant_id,
            'active' => (bool) $this->active,
            'on_call' => (bool) $this->on_call,
            'last_location' => self::coords($this->last_lat, $this->last_lng),
            'last_location_at' => self::datetime($this->last_location_at),
        ];
    }
}
