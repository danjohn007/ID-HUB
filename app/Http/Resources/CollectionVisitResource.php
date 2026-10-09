<?php

namespace App\Http\Resources;

class CollectionVisitResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'result' => self::catalog('visit_results', $this->result),
            'amount_due' => self::money($this->amount_due),
            'promise_date' => self::date($this->promise_date),
            'promise_amount' => $this->promise_amount !== null ? self::money($this->promise_amount) : null,
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? new PaymentResource($this->payment) : null),
            'notes' => $this->notes,
            'location' => self::coords($this->lat, $this->lng),
            'visited_at' => self::datetime($this->visited_at),
        ];
    }
}
