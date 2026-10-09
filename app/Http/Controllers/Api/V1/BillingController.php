<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ChargeResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PlanResource;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Planes, cargos y pagos.
 */
class BillingController extends ApiController
{
    public function plans(Request $request)
    {
        $q = Plan::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->where('active', true))
            ->when($request->has('public'), fn ($q) => $q->where('public', $request->boolean('public')))
            ->orderBy('price');

        return PlanResource::collection($q->get());
    }

    public function plan(int $id): PlanResource
    {
        return new PlanResource(Plan::query()->findOrFail($id));
    }

    public function charges(Request $request)
    {
        $q = Charge::query()->with('client')
            ->when($request->query('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->query('from'), fn ($q, $v) => $q->where('due_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('due_date', '<=', $v));
        $this->applySort($q, $request, ['id', 'due_date', 'total', 'created_at'], '-due_date');

        return ChargeResource::collection($q->paginate($this->perPage($request)));
    }

    public function payments(Request $request)
    {
        $q = Payment::query()->with(['client', 'receivedBy', 'collector'])
            ->when($request->query('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->query('method'), fn ($q, $v) => $q->whereIn('method', explode(',', $v)))
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('collector_id'), fn ($q, $v) => $q->where('collector_id', $v))
            ->when($request->query('folio'), fn ($q, $v) => $q->where('folio', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('paid_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('paid_at', '<=', $v.' 23:59:59'));
        $this->applySort($q, $request, ['id', 'paid_at', 'amount'], '-paid_at');

        return PaymentResource::collection($q->paginate($this->perPage($request)));
    }

    /** Registra un pago con BillingService (aplicación FIFO a cargos pendientes). */
    public function storePayment(Request $request): JsonResponse
    {
        $methods = array_diff(array_keys(config('wisp.payment_methods')), BillingService::ONLINE_METHODS, ['saldo', 'referido']);
        $data = $this->check($request, [
            'client_id' => ['required', 'integer', $this->existsInTenant('clients')],
            'amount' => 'required|numeric|gt:0|max:1000000',
            'method' => ['required', Rule::in(array_values($methods))],
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:190',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'notify' => 'nullable|boolean',
        ]);

        $client = Client::query()->findOrFail($data['client_id']);
        $user = $this->apiUser();
        $attrs = array_filter([
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ], fn ($v) => $v !== null);
        $attrs['notify'] = $data['notify'] ?? true;
        if ($data['method'] === 'efectivo' && $user->role === 'cobrador') {
            $attrs['collector_id'] = $user->id;
        }

        $payment = app(BillingService::class)->registerPayment($client, (float) $data['amount'], $data['method'], $attrs);
        if ($payment->collector_id && class_exists(CashCutService::class)) {
            app(CashCutService::class)->attach($payment);
        }

        return $this->ok([
            'data' => new PaymentResource($payment->fresh(['client', 'receivedBy', 'collector'])),
            'client_balance' => round((float) $client->fresh()->balance, 2),
        ], "Pago {$payment->folio} registrado.", 201);
    }
}
