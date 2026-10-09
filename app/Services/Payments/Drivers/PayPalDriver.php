<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentGateway;
use App\Models\PaymentLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PayPal Orders v2 (REST, sin SDK): crea la orden y la captura al regresar el cliente.
 * Credenciales: client_id, client_secret. Webhook opcional: POST /webhooks/paypal
 * (se verifica consultando la orden en la API).
 */
class PayPalDriver implements GatewayDriver
{
    public function __construct(protected array $credentials, protected bool $sandbox = true) {}

    public function base(): string
    {
        return $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    public function createCheckout(PaymentGateway $gateway, PaymentLink $link, array $urls): array
    {
        $tenant = $link->tenant;
        $response = Http::withToken($this->accessToken())->acceptJson()->timeout(20)
            ->withHeaders(['PayPal-Request-Id' => 'ord-'.$link->token.'-'.now()->format('YmdHi')])
            ->post($this->base().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => Str::limit($link->token, 64, ''),
                    'custom_id' => $link->token,
                    'description' => Str::limit($link->concept ?: 'Pago de servicio de Internet', 120, ''),
                    'amount' => ['currency_code' => strtoupper($tenant->currency ?: 'MXN'), 'value' => number_format((float) $link->amount, 2, '.', '')],
                ]],
                'application_context' => [
                    'brand_name' => Str::limit($tenant->name, 120, ''),
                    'locale' => 'es-MX',
                    'landing_page' => 'NO_PREFERENCE',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $urls['success'],
                    'cancel_url' => $urls['failure'],
                ],
            ]);
        if ($response->failed()) {
            throw new \RuntimeException('PayPal: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }
        $approve = collect($response->json('links', []))->firstWhere(fn ($l) => in_array($l['rel'] ?? '', ['approve', 'payer-action'], true));

        return ['url' => $approve['href'] ?? null, 'external_id' => $response->json('id')];
    }

    /** Al regresar: ?token={orderId}. Se captura la orden (idempotente) y se normaliza. */
    public function verifyReturn(PaymentGateway $gateway, PaymentLink $link, Request $request): ?array
    {
        $orderId = (string) $request->query('token');
        if ($orderId === '' || ! preg_match('/^[A-Z0-9]+$/i', $orderId)) {
            return null;
        }

        return $this->capture($orderId);
    }

    public function capture(string $orderId): ?array
    {
        $response = Http::withToken($this->accessToken())->acceptJson()->timeout(25)
            ->withHeaders(['PayPal-Request-Id' => 'cap-'.$orderId])
            ->withBody('{}', 'application/json')
            ->post($this->base().'/v2/checkout/orders/'.$orderId.'/capture');

        if ($response->failed()) {
            // Ya capturada u otro estado: consultar la orden
            return $this->fetchOrder($orderId);
        }

        return $this->fromOrder($response->json());
    }

    public function fetchOrder(string $orderId): ?array
    {
        $response = Http::withToken($this->accessToken())->acceptJson()->timeout(20)->get($this->base().'/v2/checkout/orders/'.$orderId);

        return $response->successful() ? $this->fromOrder($response->json()) : null;
    }

    public function fromOrder(array $o): array
    {
        $unit = $o['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        $status = match (true) {
            ($o['status'] ?? '') === 'COMPLETED' && ($capture['status'] ?? 'COMPLETED') === 'COMPLETED' => 'approved',
            in_array($o['status'] ?? '', ['VOIDED'], true) || in_array($capture['status'] ?? '', ['DECLINED', 'FAILED'], true) => 'rejected',
            default => 'pending',
        };

        return [
            'status' => $status,
            'raw_status' => ($o['status'] ?? '').($capture ? '/'.$capture['status'] : ''),
            'external_id' => (string) ($o['id'] ?? ''),
            'amount' => (float) ($capture['amount']['value'] ?? $unit['amount']['value'] ?? 0),
            'fee' => isset($capture['seller_receivable_breakdown']['paypal_fee']['value']) ? (float) $capture['seller_receivable_breakdown']['paypal_fee']['value'] : null,
            'token' => $unit['custom_id'] ?? ($capture['custom_id'] ?? null) ?? ($unit['reference_id'] ?? null),
        ];
    }

    protected function accessToken(): string
    {
        $id = $this->credentials['client_id'] ?? null;
        $secret = $this->credentials['client_secret'] ?? null;
        if (! $id || ! $secret) {
            throw new \RuntimeException('PayPal: faltan client_id / client_secret.');
        }

        return Cache::remember('paypal-token:'.md5($id.$this->base()), 60 * 50, function () use ($id, $secret) {
            $response = Http::asForm()->withBasicAuth($id, $secret)->timeout(20)
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if ($response->failed()) {
                throw new \RuntimeException('PayPal: no se pudo autenticar ('.$response->status().')');
            }

            return $response->json('access_token');
        });
    }
}
