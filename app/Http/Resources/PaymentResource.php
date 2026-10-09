<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\URL;

class PaymentResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? new ClientSummaryResource($this->client) : null),
            'kind' => $this->kind,
            'method' => $this->method,
            'method_label' => $this->methodLabel(),
            'amount' => self::money($this->amount),
            'amount_label' => money($this->amount),
            'reference' => $this->reference,
            'status' => self::catalog('payment_status', $this->status),
            'paid_at' => self::datetime($this->paid_at),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy ? new UserSummaryResource($this->receivedBy) : null),
            'collector' => $this->whenLoaded('collector', fn () => $this->collector ? new UserSummaryResource($this->collector) : null),
            'cash_cut_id' => $this->cash_cut_id,
            'location' => self::coords($this->lat, $this->lng),
            'notes' => $this->notes,
            'receipt_url' => self::receiptUrl($this->resource),
        ];
    }

    /** Enlace firmado (7 días) al comprobante PDF; no requiere sesión. */
    public static function receiptUrl($payment): ?string
    {
        if ($payment->status !== 'completed') {
            return null;
        }

        return URL::temporarySignedRoute('api.v1.receipt', now()->addDays(7), ['payment' => $payment->id]);
    }
}
