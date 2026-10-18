<?php

namespace App\Http\Resources;

/** Corte de caja del cobrador (sección 27). status: open | closed | delivered | reconciled */
class CashCutResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => self::catalog('cash_cut_status', $this->displayStatus()),
            'opened_at' => self::datetime($this->opened_at),
            'closed_at' => self::datetime($this->closed_at),
            'payments_count' => (int) $this->payments_count,
            'expected_amount' => self::money($this->expected_amount),
            'delivered_amount' => $this->delivered_amount !== null ? self::money($this->delivered_amount) : null,
            'difference' => $this->difference !== null ? self::money($this->difference) : null,
            'reconciled_at' => self::datetime($this->reconciled_at),
            'reconciled_by' => $this->whenLoaded('reconciledBy', fn () => $this->reconciledBy ? new UserSummaryResource($this->reconciledBy) : null),
            'notes' => $this->notes,
        ];
    }
}
