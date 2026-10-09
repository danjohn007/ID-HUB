<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentGateway;
use App\Models\PaymentLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mercado Pago Checkout Pro (REST, sin SDK).
 * Credenciales: access_token (requerido), webhook_secret (opcional, valida x-signature).
 * Webhook: POST /webhooks/mercadopago?link={token}  — se verifica consultando GET /v1/payments/{id}.
 */
class MercadoPagoDriver implements GatewayDriver
{
    public const API = 'https://api.mercadopago.com';

    public function __construct(protected array $credentials) {}

    public function createCheckout(PaymentGateway $gateway, PaymentLink $link, array $urls): array
    {
        $client = $link->client;
        $tenant = $link->tenant;
        $payload = array_filter([
            'items' => [[
                'id' => (string) $link->id,
                'title' => $link->concept ?: 'Pago de servicio de Internet',
                'quantity' => 1,
                'currency_id' => $tenant->currency ?: 'MXN',
                'unit_price' => round((float) $link->amount, 2),
            ]],
            'payer' => array_filter(['name' => $client?->first_name, 'surname' => $client?->last_name, 'email' => $client?->email]),
            'external_reference' => $link->token,
            'back_urls' => ['success' => $urls['success'], 'failure' => $urls['failure'], 'pending' => $urls['success']],
            'auto_return' => 'approved',
            'notification_url' => str_starts_with($urls['webhook'], 'https://') ? $urls['webhook'] : null,
            'statement_descriptor' => Str::limit(Str::ascii($tenant->name), 22, ''),
            'expires' => (bool) $link->expires_at,
            'expiration_date_to' => $link->expires_at?->format('Y-m-d\TH:i:s.vP'),
            'metadata' => ['link_token' => $link->token, 'tenant_id' => $tenant->id],
        ], fn ($v) => $v !== null && $v !== []);

        $response = Http::withToken($this->token())->acceptJson()->timeout(20)
            ->withHeaders(['X-Idempotency-Key' => 'pref-'.$link->token.'-'.now()->format('YmdHi')])
            ->post(self::API.'/checkout/preferences', $payload);
        if ($response->failed()) {
            throw new \RuntimeException('Mercado Pago: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return [
            'url' => $gateway->sandbox ? ($response->json('sandbox_init_point') ?? $response->json('init_point')) : $response->json('init_point'),
            'external_id' => $response->json('id'),
        ];
    }

    public function verifyReturn(PaymentGateway $gateway, PaymentLink $link, Request $request): ?array
    {
        $id = $request->query('payment_id') ?? $request->query('collection_id');
        if (! $id || $id === 'null') {
            return null;
        }

        return $this->fetchPayment((string) $id);
    }

    /** Consulta un pago en la API (fuente de verdad para webhooks y retornos). */
    public function fetchPayment(string $paymentId): ?array
    {
        if (! preg_match('/^\d+$/', $paymentId)) {
            return null;
        }
        $response = Http::withToken($this->token())->acceptJson()->timeout(20)->get(self::API.'/v1/payments/'.$paymentId);
        if ($response->failed()) {
            return null;
        }
        $p = $response->json();
        $status = match ($p['status'] ?? '') {
            'approved' => 'approved',
            'pending', 'in_process', 'authorized' => 'pending',
            default => 'rejected',
        };
        $fee = collect($p['fee_details'] ?? [])->sum('amount');

        return [
            'status' => $status,
            'raw_status' => $p['status'] ?? '',
            'external_id' => (string) $p['id'],
            'amount' => (float) ($p['transaction_amount'] ?? 0),
            'fee' => $fee > 0 ? round((float) $fee, 2) : null,
            'token' => $p['external_reference'] ?? null,
            'method_detail' => $p['payment_method_id'] ?? null,
        ];
    }

    /**
     * Valida la firma x-signature (si hay webhook_secret configurado).
     * Manifest: "id:{data.id};request-id:{x-request-id};ts:{ts};"
     */
    public function validSignature(Request $request, string $dataId): bool
    {
        $secret = $this->credentials['webhook_secret'] ?? null;
        if (! $secret) {
            return true; // Sin secreto: la verificación se hace consultando el pago en la API.
        }
        $parts = collect(explode(',', (string) $request->header('x-signature')))
            ->mapWithKeys(function ($p) {
                [$k, $v] = array_pad(explode('=', trim($p), 2), 2, null);

                return [$k => $v];
            });
        if (! $parts->get('ts') || ! $parts->get('v1')) {
            return false;
        }
        $manifest = 'id:'.strtolower($dataId).';request-id:'.$request->header('x-request-id').';ts:'.$parts['ts'].';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), (string) $parts['v1']);
    }

    protected function token(): string
    {
        $token = $this->credentials['access_token'] ?? null;
        if (! $token) {
            throw new \RuntimeException('Mercado Pago: falta el access_token.');
        }

        return $token;
    }
}
