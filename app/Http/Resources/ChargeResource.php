<?php

namespace App\Http\Resources;

class ChargeResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? new ClientSummaryResource($this->client) : null),
            'type' => $this->type,
            'type_label' => config("wisp.charge_types.{$this->type}", $this->type),
            'description' => $this->description,
            'period_start' => self::date($this->period_start),
            'period_end' => self::date($this->period_end),
            'subtotal' => self::money($this->subtotal),
            'tax' => self::money($this->tax),
            'total' => self::money($this->total),
            'paid_amount' => self::money($this->paid_amount),
            'balance' => $this->balance(),
            'due_date' => self::date($this->due_date),
            'overdue' => $this->isOverdue(),
            'status' => self::catalog('charge_status', $this->status),
            'paid_at' => self::datetime($this->paid_at),
            'created_at' => self::datetime($this->created_at),
        ];
    }
}
