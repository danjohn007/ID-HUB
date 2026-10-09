<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentLink;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Services\Payments\Drivers\GatewayDriver;
use App\Services\Payments\Drivers\MercadoPagoDriver;
use App\Services\Payments\Drivers\PayPalDriver;
use App\Services\Payments\Drivers\SandboxDriver;
use App\Services\Payments\Drivers\StripeDriver;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pagos en línea (sección 7): Mercado Pago, Stripe (tarjeta), OXXO Pay (vía Stripe), PayPal
 * y un checkout simulado para sandbox sin credenciales.
 *
 * Flujo: enlace "Pagar ahora" → checkout en la pasarela → confirmación (webhook o regreso
 * verificado contra la API) → confirm(): registra el pago (BillingService::registerPayment),
 * marca el enlace como pagado; la reconexión la ejecuta la regla de automatización
 * "payment_received" y el cliente recibe la confirmación por mensaje.
 *
 * Idempotente: un mismo (gateway, external_id) nunca se registra dos veces.
 *
 * Credenciales (PaymentGateway::credentials, ver config('wisp.gateway_credentials')):
 *   mercadopago: access_token, public_key?, webhook_secret?
 *   stripe:      secret_key, publishable_key?, webhook_secret
 *   oxxo:        (opcional) secret_key, webhook_secret — si faltan usa las de Stripe
 *   paypal:      client_id, client_secret, webhook_id?
 */
class PaymentGatewayService
{
    public const PROVIDERS = ['mercadopago', 'stripe', 'oxxo', 'paypal'];

    public const REQUIRED = [
        'mercadopago' => ['access_token'],
        'stripe' => ['secret_key'],
        'oxxo' => ['secret_key'],
        'paypal' => ['client_id', 'client_secret'],
    ];

    /** Pasarelas habilitadas del WISP (orden de presentación fijo). */
    public function enabledFor(Tenant $tenant): Collection
    {
        return PaymentGateway::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('enabled', true)
            ->whereIn('provider', self::PROVIDERS)->get()
            ->filter(fn (PaymentGateway $g) => $this->hasCredentials($g) || $g->sandbox)
            ->sortBy(fn ($g) => array_search($g->provider, self::PROVIDERS, true))->values();
    }

    /** Credenciales efectivas (OXXO hereda las de Stripe cuando no tiene propias). */
    public function credentialsFor(PaymentGateway $gateway): array
    {
        $creds = array_filter((array) ($gateway->credentials ?? []), fn ($v) => $v !== null && $v !== '');
        if ($gateway->provider === 'oxxo') {
            $stripe = PaymentGateway::withoutGlobalScopes()->where('tenant_id', $gateway->tenant_id)->where('provider', 'stripe')->first();
            $creds += array_filter((array) ($stripe?->credentials ?? []), fn ($v) => $v !== null && $v !== '');
        }

        return $creds;
    }

    public function hasCredentials(PaymentGateway $gateway): bool
    {
        $creds = $this->credentialsFor($gateway);
        foreach (self::REQUIRED[$gateway->provider] ?? ['__none'] as $key) {
            if (empty($creds[$key])) {
                return false;
            }
        }

        return true;
    }

    /** Sandbox sin credenciales → checkout simulado. */
    public function isSimulated(PaymentGateway $gateway): bool
    {
        return $gateway->sandbox && ! $this->hasCredentials($gateway);
    }

    public function driver(PaymentGateway $gateway): GatewayDriver
    {
        if ($this->isSimulated($gateway)) {
            return new SandboxDriver;
        }
        $creds = $this->credentialsFor($gateway);

        return match ($gateway->provider) {
            'mercadopago' => new MercadoPagoDriver($creds),
            'stripe', 'oxxo' => new StripeDriver($creds),
            'paypal' => new PayPalDriver($creds, (bool) $gateway->sandbox),
            default => throw new \InvalidArgumentException("Pasarela no soportada: {$gateway->provider}"),
        };
    }

    public function gatewayFor(PaymentLink $link, string $provider): ?PaymentGateway
    {
        return $this->enabledFor($link->tenant)->firstWhere('provider', $provider);
    }

    /** Carga un enlace por token sin scope y fija el WISP en el contexto. */
    public function resolveLink(string $token): ?PaymentLink
    {
        $link = PaymentLink::withoutGlobalScopes()->with(['tenant', 'client' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('token', $token)->first();
        if ($link && $link->tenant) {
            app(TenantContext::class)->set($link->tenant);
        }

        return $link?->tenant ? $link : null;
    }

    /** Inicia el checkout y devuelve la URL a la que se redirige al cliente. */
    public function startCheckout(PaymentLink $link, string $provider): string
    {
        if (! $link->isPayable()) {
            throw new \RuntimeException('Este enlace de pago ya no está disponible.');
        }
        $gateway = $this->gatewayFor($link, $provider);
        if (! $gateway) {
            throw new \RuntimeException('Método de pago no disponible.');
        }

        $urls = [
            'success' => route('pay.success', ['token' => $link->token, 'provider' => $provider]),
            'failure' => route('pay.failure', ['token' => $link->token, 'provider' => $provider]),
            'webhook' => route('pay.webhook', ['provider' => $provider === 'oxxo' ? 'stripe' : $provider, 'link' => $link->token]),
        ];
        $result = $this->driver($gateway)->createCheckout($gateway, $link, $urls);
        if (empty($result['url'])) {
            throw new \RuntimeException('La pasarela no devolvió una URL de pago.');
        }
        $link->forceFill([
            'gateway' => $provider,
            'external_id' => $result['external_id'] ?? null,
            'checkout_url' => mb_substr($result['url'], 0, 500),
        ])->save();

        return $result['url'];
    }

    /**
     * Regreso del cliente desde la pasarela: verifica contra la API y confirma si procede.
     * Devuelve ['status' => approved|pending|rejected|unknown, 'payment' => ?Payment].
     */
    public function handleReturn(PaymentLink $link, string $provider, Request $request): array
    {
        $existing = $this->paymentForLink($link);
        if ($existing) {
            return ['status' => 'approved', 'payment' => $existing];
        }
        $gateway = PaymentGateway::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->where('provider', $provider)->first();
        if (! $gateway || $this->isSimulated($gateway)) {
            return ['status' => 'unknown', 'payment' => null];
        }

        try {
            $result = $this->driver($gateway)->verifyReturn($gateway, $link, $request);
        } catch (\Throwable $e) {
            Log::warning("Pago en línea {$provider}: error al verificar regreso", ['link' => $link->id, 'error' => $e->getMessage()]);
            $result = null;
        }
        if (! $result) {
            return ['status' => 'unknown', 'payment' => null];
        }
        if (($result['token'] ?? null) && $result['token'] !== $link->token) {
            Log::warning("Pago en línea {$provider}: token no coincide", ['link' => $link->id]);

            return ['status' => 'unknown', 'payment' => null];
        }
        if ($result['status'] !== 'approved') {
            return ['status' => $result['status'], 'payment' => null];
        }

        return ['status' => 'approved', 'payment' => $this->confirm($link, $provider, $result['external_id'], $result['amount'], $result['fee'] ?? null)];
    }

    /**
     * Procesa un webhook. Devuelve [httpStatus, mensaje].
     * mercadopago: ?link={token}, body {type: payment, data: {id}}; se consulta /v1/payments/{id}.
     * stripe/oxxo: evento firmado (Stripe-Signature) checkout.session.completed / async_payment_succeeded.
     * paypal: CHECKOUT.ORDER.APPROVED / PAYMENT.CAPTURE.COMPLETED; se verifica consultando la orden.
     */
    public function handleWebhook(string $provider, Request $request): array
    {
        return match ($provider) {
            'mercadopago' => $this->mercadoPagoWebhook($request),
            'stripe', 'oxxo' => $this->stripeWebhook($request),
            'paypal' => $this->payPalWebhook($request),
            default => [404, 'Proveedor desconocido'],
        };
    }

    /** Checkout simulado: aprobar o rechazar. */
    public function simulate(PaymentLink $link, string $provider, bool $approve): ?Payment
    {
        $gateway = $this->gatewayFor($link, $provider);
        if (! $gateway || ! $this->isSimulated($gateway)) {
            throw new \RuntimeException('El checkout simulado no está disponible para esta pasarela.');
        }
        if (! $approve) {
            return null;
        }
        $externalId = $link->gateway === $provider && str_starts_with((string) $link->external_id, 'SBX-')
            ? $link->external_id
            : 'SBX-'.strtoupper($provider).'-'.$link->id.'-'.now()->format('His');

        return $this->confirm($link, $provider, $externalId, (float) $link->amount, null);
    }

    /**
     * Registra el pago confirmado (idempotente) y marca el enlace como pagado.
     */
    public function confirm(PaymentLink $link, string $provider, string $externalId, float $amount, ?float $fee = null): ?Payment
    {
        if ($externalId === '' || $amount <= 0) {
            return null;
        }
        $tenant = Tenant::find($link->tenant_id);

        return app(TenantContext::class)->run($tenant, function () use ($link, $provider, $externalId, $amount, $fee) {
            return DB::transaction(function () use ($link, $provider, $externalId, $amount, $fee) {
                // Bloqueo del enlace: serializa webhooks y retornos simultáneos
                $locked = PaymentLink::withoutGlobalScopes()->whereKey($link->id)->lockForUpdate()->first();

                $existing = Payment::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)
                    ->where('gateway', $provider)->where('external_id', $externalId)->first();
                if ($existing) {
                    return $existing;
                }

                $client = $locked->client()->withoutGlobalScopes()->first();
                if (! $client) {
                    return null;
                }
                $gateway = PaymentGateway::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('provider', $provider)->first();
                $gatewayFee = $fee ?? ($gateway ? $gateway->feeFor($amount) : 0);
                $label = config("wisp.gateways.$provider", $provider);

                $payment = app(BillingService::class)->registerPayment($client, $amount, $provider, [
                    'gateway' => $provider,
                    'external_id' => $externalId,
                    'reference' => mb_substr($externalId, 0, 120),
                    'gateway_fee' => round($gatewayFee, 2),
                    'payment_link_id' => $locked->id,
                    'notes' => "Pago en línea vía {$label}",
                ]);

                $locked->forceFill([
                    'status' => 'paid',
                    'paid_at' => $locked->paid_at ?? now(),
                    'gateway' => $provider,
                    'external_id' => $externalId,
                ])->save();

                Audit::log('online_payment', "confirmó pago en línea {$payment->folio} vía {$label} (".money($amount).')', $payment);

                return $payment;
            });
        });
    }

    /** Pago ya registrado para el enlace (si existe). */
    public function paymentForLink(PaymentLink $link): ?Payment
    {
        return Payment::withoutGlobalScopes()->where('payment_link_id', $link->id)->where('status', 'completed')->latest('id')->first();
    }

    // ------------------------------------------------------------------ webhooks

    protected function mercadoPagoWebhook(Request $request): array
    {
        $type = $request->input('type') ?? $request->input('topic') ?? $request->query('type') ?? $request->query('topic');
        $id = (string) ($request->input('data.id') ?? $request->query('data_id') ?? $request->query('data.id') ?? $request->query('id') ?? '');
        if ($type !== 'payment' || $id === '') {
            return [200, 'Evento ignorado'];
        }
        $link = $this->resolveLink((string) $request->query('link'));
        if (! $link) {
            return [404, 'Enlace no encontrado'];
        }
        $gateway = PaymentGateway::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->where('provider', 'mercadopago')->first();
        if (! $gateway || ! $this->hasCredentials($gateway)) {
            return [400, 'Pasarela sin credenciales'];
        }
        /** @var MercadoPagoDriver $driver */
        $driver = $this->driver($gateway);
        if (! $driver->validSignature($request, $id)) {
            return [401, 'Firma inválida'];
        }
        $result = $driver->fetchPayment($id);
        if (! $result || $result['token'] !== $link->token) {
            return [400, 'Pago no verificable'];
        }
        if ($result['status'] === 'approved') {
            $payment = $this->confirm($link, 'mercadopago', $result['external_id'], $result['amount'], $result['fee']);

            return [200, 'Pago aplicado '.$payment?->folio];
        }

        return [200, 'Estado '.$result['raw_status']];
    }

    protected function stripeWebhook(Request $request): array
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        $session = $event['data']['object'] ?? null;
        if (! is_array($session) || ($session['object'] ?? '') !== 'checkout.session') {
            return [200, 'Evento ignorado'];
        }
        $token = $session['client_reference_id'] ?? ($session['metadata']['link_token'] ?? null);
        $link = $token ? $this->resolveLink((string) $token) : null;
        if (! $link) {
            return [404, 'Enlace no encontrado'];
        }
        $provider = in_array($session['metadata']['provider'] ?? '', ['stripe', 'oxxo'], true) ? $session['metadata']['provider'] : 'stripe';
        $gateway = PaymentGateway::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->where('provider', $provider)->first();
        if (! $gateway) {
            return [400, 'Pasarela no configurada'];
        }
        $driver = new StripeDriver($this->credentialsFor($gateway));
        if (! $driver->validSignature($payload, $request->header('Stripe-Signature'))) {
            return [401, 'Firma inválida'];
        }
        if (! in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return [200, 'Evento '.($event['type'] ?? '?').' registrado'];
        }
        $result = $driver->fromSession($session);
        if ($result['status'] !== 'approved') {
            return [200, 'Pago pendiente ('.$result['raw_status'].')'];
        }
        $payment = $this->confirm($link, $provider, $result['external_id'], $result['amount'], null);

        return [200, 'Pago aplicado '.$payment?->folio];
    }

    protected function payPalWebhook(Request $request): array
    {
        $type = (string) $request->input('event_type');
        $resource = (array) $request->input('resource', []);
        $orderId = match ($type) {
            'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED' => $resource['id'] ?? null,
            'PAYMENT.CAPTURE.COMPLETED' => $resource['supplementary_data']['related_ids']['order_id'] ?? null,
            default => null,
        };
        if (! $orderId) {
            return [200, 'Evento ignorado'];
        }
        $token = $resource['custom_id'] ?? ($resource['purchase_units'][0]['custom_id'] ?? null);
        $link = $token ? $this->resolveLink((string) $token) : null;
        if (! $link) {
            $found = PaymentLink::withoutGlobalScopes()->where('gateway', 'paypal')->where('external_id', $orderId)->first();
            $link = $found ? $this->resolveLink($found->token) : null;
        }
        if (! $link) {
            return [404, 'Enlace no encontrado'];
        }
        $gateway = PaymentGateway::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->where('provider', 'paypal')->first();
        if (! $gateway || ! $this->hasCredentials($gateway)) {
            return [400, 'Pasarela sin credenciales'];
        }
        /** @var PayPalDriver $driver */
        $driver = $this->driver($gateway);
        // Verificación: se consulta (o captura) la orden directamente en PayPal
        $result = $type === 'CHECKOUT.ORDER.APPROVED' ? $driver->capture($orderId) : $driver->fetchOrder($orderId);
        if (! $result || ($result['token'] && $result['token'] !== $link->token)) {
            return [400, 'Orden no verificable'];
        }
        if ($result['status'] !== 'approved') {
            return [200, 'Estado '.$result['raw_status']];
        }
        $payment = $this->confirm($link, 'paypal', $result['external_id'], $result['amount'], $result['fee']);

        return [200, 'Pago aplicado '.$payment?->folio];
    }
}
