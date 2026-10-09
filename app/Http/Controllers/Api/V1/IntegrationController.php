<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ClientResource;
use App\Http\Resources\PaymentResource;
use App\Models\Client;
use App\Models\Payment;
use App\Services\BillingService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Integraciones con API key por WISP (sección 50): consulta de clientes y registro
 * idempotente de pagos externos (banco, ERP, corresponsales, etc.).
 */
class IntegrationController extends ApiController
{
    /** Genera (o rota) la API key del WISP. Se muestra una sola vez. Requiere settings.manage. */
    public function generateKey(): JsonResponse
    {
        $tenant = tenant();
        $key = 'wc_'.$tenant->id.'_'.Str::random(40);
        $tenant->putSettings('api', ['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'key_created_at' => now()->toIso8601String()]);
        Audit::log('api_key', 'generó una nueva API key de integraciones', $tenant);

        return $this->ok([
            'api_key' => $key,
            'header' => 'X-Api-Key',
        ], 'API key generada. Guárdala en un lugar seguro: no se volverá a mostrar y la anterior dejó de funcionar.', 201);
    }

    public function client(string $code): JsonResponse
    {
        $client = Client::query()->with(['plan', 'zone'])->where('code', $code)->orWhere('pppoe_user', $code)->firstOrFail();

        return $this->ok(['data' => new ClientResource($client)]);
    }

    public function clients(Request $request)
    {
        $q = Client::query()->with(['plan', 'zone'])
            ->when($request->query('updated_since'), fn ($q, $v) => $q->where('updated_at', '>=', $v))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('service_status', explode(',', $s)))
            ->orderBy('id');

        return ClientResource::collection($q->paginate($this->perPage($request, 50)));
    }

    /** Pago externo idempotente por external_id. */
    public function payment(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'client_code' => 'required|string|max:80',
            'amount' => 'required|numeric|gt:0|max:1000000',
            'method' => ['required', Rule::in(['transferencia', 'deposito', 'tarjeta', 'efectivo'])],
            'external_id' => 'required|string|max:120',
            'reference' => 'nullable|string|max:120',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:190',
        ], ['client_code' => 'ID de cliente', 'external_id' => 'ID externo']);

        $existing = Payment::query()->where('external_id', $data['external_id'])->where('gateway', 'api')->first();
        if ($existing) {
            return $this->ok(['data' => new PaymentResource($existing->load('client')), 'duplicate' => true], "El pago ya estaba registrado ({$existing->folio}).");
        }

        $client = Client::query()->where('code', $data['client_code'])->orWhere('pppoe_user', $data['client_code'])->first();
        if (! $client) {
            return $this->ok([], 'Cliente no encontrado.', 404);
        }

        $payment = app(BillingService::class)->registerPayment($client, (float) $data['amount'], $data['method'], array_filter([
            'gateway' => 'api',
            'external_id' => $data['external_id'],
            'reference' => $data['reference'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'notes' => $data['notes'] ?? 'Pago registrado por integración (API)',
            'received_by' => null,
        ], fn ($v) => $v !== null));

        return $this->ok([
            'data' => new PaymentResource($payment->load('client')),
            'duplicate' => false,
            'client_balance' => round((float) $client->fresh()->balance, 2),
        ], "Pago {$payment->folio} registrado.", 201);
    }
}
