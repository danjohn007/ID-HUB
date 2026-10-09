<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentGateway;
use App\Models\PaymentLink;
use Illuminate\Http\Request;

/**
 * Contrato de una pasarela de pago. Resultado normalizado de verificación:
 *   ['status' => 'approved'|'pending'|'rejected', 'external_id' => string, 'amount' => float,
 *    'fee' => ?float, 'token' => ?string (token del PaymentLink), 'raw_status' => string]
 */
interface GatewayDriver
{
    /**
     * Crea el checkout en la pasarela.
     * $urls: success, failure, webhook. Devuelve ['url' => string, 'external_id' => ?string].
     */
    public function createCheckout(PaymentGateway $gateway, PaymentLink $link, array $urls): array;

    /** Verifica el regreso del cliente (back URL). */
    public function verifyReturn(PaymentGateway $gateway, PaymentLink $link, Request $request): ?array;
}
