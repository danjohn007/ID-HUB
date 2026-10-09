<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentGateway;
use App\Models\PaymentLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Checkout simulado: se usa cuando la pasarela está habilitada en modo sandbox y
 * sin credenciales. Muestra una página propia con "Aprobar pago" / "Rechazar".
 */
class SandboxDriver implements GatewayDriver
{
    public function createCheckout(PaymentGateway $gateway, PaymentLink $link, array $urls): array
    {
        return [
            'url' => route('pay.sandbox', [$link->token, $gateway->provider]),
            'external_id' => 'SBX-'.strtoupper($gateway->provider).'-'.Str::upper(Str::random(10)),
        ];
    }

    public function verifyReturn(PaymentGateway $gateway, PaymentLink $link, Request $request): ?array
    {
        return null; // El resultado lo resuelve PaymentGatewayService::simulate()
    }
}
