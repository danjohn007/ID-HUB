<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentGateway;
use App\Models\PaymentLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout Session (REST, sin SDK). Tarjeta (provider "stripe") u OXXO Pay (provider "oxxo").
 * Credenciales: secret_key, webhook_secret (firma Stripe-Signature).
 * Webhook: POST /webhooks/stripe  (eventos checkout.session.completed y checkout.session.async_payment_succeeded).
 */
class StripeDriver implements GatewayDriver
{
    public const API = 'https://api.stripe.com/v1';

    public function __construct(protected array $credentials) {}

    public function createCheckout(PaymentGateway $gateway, PaymentLink $link, array $urls): array
    {
        $tenant = $link->tenant;
        $client = $link->client;
        $oxxo = $gateway->provider === 'oxxo';
        $currency = strtolower($tenant->currency ?: 'MXN');

        $payload = array_filter([
            'mode' => 'payment',
            'payment_method_types' => [$oxxo ? 'oxxo' : 'card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $oxxo ? 'mxn' : $currency,
                    'unit_amount' => (int) round((float) $link->amount * 100),
                    'product_data' => ['name' => $link->concept ?: 'Pago de servicio de Internet'],
                ],
            ]],
            // {CHECKOUT_SESSION_ID} lo sustituye Stripe; no debe ir codificado
            'success_url' => $urls['success'].(str_contains($urls['success'], '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $urls['failure'],
            'client_reference_id' => $link->token,
            'customer_email' => $client?->email ?: null,
            'locale' => 'es',
            'metadata' => ['link_token' => $link->token, 'provider' => $gateway->provider, 'tenant_id' => (string) $tenant->id],
            'payment_intent_data' => ['description' => $link->concept, 'metadata' => ['link_token' => $link->token]],
            'payment_method_options' => $oxxo ? ['oxxo' => ['expires_after_days' => 3]] : null,
        ], fn ($v) => $v !== null);

        $response = Http::asForm()->withToken($this->secret())->timeout(20)
            ->withHeaders(['Idempotency-Key' => 'cs-'.$link->token.'-'.$gateway->provider.'-'.now()->format('YmdHi')])
            ->post(self::API.'/checkout/sessions', $payload);
        if ($response->failed()) {
            throw new \RuntimeException('Stripe: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return ['url' => $response->json('url'), 'external_id' => $response->json('id')];
    }

    public function verifyReturn(PaymentGateway $gateway, PaymentLink $link, Request $request): ?array
    {
        $id = (string) $request->query('session_id');
        if (! str_starts_with($id, 'cs_')) {
            return null;
        }
        $response = Http::withToken($this->secret())->timeout(20)->get(self::API.'/checkout/sessions/'.$id);

        return $response->successful() ? $this->fromSession($response->json()) : null;
    }

    /** Normaliza un objeto checkout.session. El external_id es siempre el id de la sesión (cs_...). */
    public function fromSession(array $s): array
    {
        $status = match (true) {
            ($s['payment_status'] ?? '') === 'paid' => 'approved',
            ($s['status'] ?? '') === 'complete' => 'pending',   // p. ej. ficha OXXO generada, aún sin pagar
            ($s['status'] ?? '') === 'expired' => 'rejected',
            default => 'pending',
        };

        return [
            'status' => $status,
            'raw_status' => ($s['status'] ?? '').'/'.($s['payment_status'] ?? ''),
            'external_id' => (string) $s['id'],
            'amount' => round(((int) ($s['amount_total'] ?? 0)) / 100, 2),
            'fee' => null,
            'token' => $s['client_reference_id'] ?? ($s['metadata']['link_token'] ?? null),
            'provider' => $s['metadata']['provider'] ?? null,
        ];
    }

    /** Verifica el encabezado Stripe-Signature (t=..., v1=...) con tolerancia de 5 minutos. */
    public function validSignature(string $payload, ?string $header, int $tolerance = 300): bool
    {
        $secret = $this->credentials['webhook_secret'] ?? null;
        if (! $secret || ! $header) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || ! $signatures || abs(time() - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    protected function secret(): string
    {
        $key = $this->credentials['secret_key'] ?? null;
        if (! $key) {
            throw new \RuntimeException('Stripe: falta la secret_key.');
        }

        return $key;
    }
}
