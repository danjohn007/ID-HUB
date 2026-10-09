<?php

namespace App\Http\Resources;

class ClientSummaryResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'address' => trim($this->address.' '.$this->colonia),
            'location' => self::coords($this->lat, $this->lng),
            'service_status' => self::catalog('client_status', $this->service_status),
            'balance' => self::money($this->balance),
        ];
    }
}
