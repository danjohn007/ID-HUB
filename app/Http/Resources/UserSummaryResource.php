<?php

namespace App\Http\Resources;

class UserSummaryResource extends ApiResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'phone' => $this->phone, 'role' => $this->role];
    }
}
