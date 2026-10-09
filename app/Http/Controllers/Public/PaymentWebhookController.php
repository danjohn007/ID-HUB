<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks de pasarelas de pago (CSRF excluido para webhooks/*).
 * Siempre responde rápido; los errores se registran en el log.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentGatewayService $gateways)
    {
        try {
            [$status, $message] = $gateways->handleWebhook($provider, $request);
        } catch (\Throwable $e) {
            report($e);
            [$status, $message] = [500, 'Error al procesar'];
        }
        Log::info("Webhook {$provider}: {$status} {$message}");

        return response()->json(['ok' => $status < 300, 'message' => $message], $status);
    }
}
