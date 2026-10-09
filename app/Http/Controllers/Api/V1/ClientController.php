<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ChargeResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PaymentResource;
use App\Models\Client;
use App\Models\Plan;
use App\Services\BillingService;
use App\Services\ReferralService;
use App\Services\ServiceControl;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Clientes del WISP (personal autorizado).
 */
class ClientController extends ApiController
{
    protected const WITH = ['plan', 'zone', 'repeater', 'technician', 'collector'];

    public function index(Request $request)
    {
        $q = Client::query()->with(['plan', 'zone'])
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('service_status', explode(',', $s)))
            ->when($request->query('zone_id'), fn ($q, $v) => $q->where('zone_id', $v))
            ->when($request->query('plan_id'), fn ($q, $v) => $q->where('plan_id', $v))
            ->when($request->query('repeater_id'), fn ($q, $v) => $q->where('repeater_id', $v))
            ->when($request->query('collector_id'), fn ($q, $v) => $q->where('collector_id', $v))
            ->when($request->query('technician_id'), fn ($q, $v) => $q->where('technician_id', $v))
            ->when($request->boolean('with_balance'), fn ($q) => $q->where('balance', '>', 0))
            ->when($request->has('online'), fn ($q) => $q->where('online', $request->boolean('online')))
            ->when($request->query('updated_since'), fn ($q, $v) => $q->where('updated_at', '>=', $v));

        $this->applySort($q, $request, ['id', 'code', 'first_name', 'balance', 'next_due_date', 'created_at', 'updated_at'], 'id');

        return ClientResource::collection($q->paginate($this->perPage($request)));
    }

    public function show(int $id): ClientResource
    {
        return new ClientResource(Client::query()->with(self::WITH)->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->check($request, $this->rules());
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $activate = $request->boolean('activate', true);
        $chargeInstallation = $request->boolean('charge_installation', true);
        $referral = $data['referral_code'] ?? null;
        unset($data['referral_code']);

        $data['code'] ??= $this->nextCode();
        $data['service_status'] ??= 'activo';
        $data['billing_day'] ??= (int) tenant()->setting('billing.billing_day', 1);
        $data['pppoe_user'] ??= 'c'.$data['code'];
        $data['pppoe_password'] ??= Str::lower(Str::random(8));
        $data['installation_date'] ??= now()->toDateString();
        if (! empty($data['mac_address'])) {
            $data['mac_address'] = strtoupper(str_replace('-', ':', $data['mac_address']));
        }
        if (Client::query()->where('code', $data['code'])->exists()) {
            return $this->ok(['errors' => ['code' => ['El ID de cliente ya está registrado.']]], 'Los datos enviados no son válidos.', 422);
        }
        if (tenant()->exceedsLimit('clients', $data)) {
            return $this->ok(['errors' => ['service_status' => [tenant()->limitMessage('clients')]]], 'Límite del plan alcanzado.', 422);
        }

        $client = Client::create($data);
        Audit::log('created', "registró cliente #{$client->code} (API)", $client);
        app(ReferralService::class)->ensureCode($client);
        if ($referral) {
            app(ReferralService::class)->register($client, $referral);
        }
        if ($chargeInstallation && ($fee = (float) $client->plan?->installation_fee) > 0) {
            app(BillingService::class)->createCharge($client, 'instalacion', 'Instalación de servicio', $fee, now());
        }
        if ($activate && $client->service_status === 'activo') {
            try {
                app(ServiceControl::class)->activate($client);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        app(BillingService::class)->recalculate($client->fresh());

        return $this->ok(['data' => new ClientResource($client->fresh(self::WITH))], "Cliente #{$client->code} registrado.", 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::query()->findOrFail($id);
        $data = $this->check($request, $this->rules($client));
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        unset($data['referral_code']);
        if (! empty($data['mac_address'])) {
            $data['mac_address'] = strtoupper(str_replace('-', ':', $data['mac_address']));
        }
        if (isset($data['code']) && Client::query()->where('code', $data['code'])->where('id', '!=', $client->id)->exists()) {
            return $this->ok(['errors' => ['code' => ['El ID de cliente ya está registrado.']]], 'Los datos enviados no son válidos.', 422);
        }

        $newPlan = null;
        if (array_key_exists('plan_id', $data)) {
            if ((int) $data['plan_id'] !== (int) $client->plan_id) {
                $newPlan = Plan::find($data['plan_id']);
            }
            unset($data['plan_id']);
        }
        // Los cambios de estado de servicio se hacen con ServiceControl (MikroTik), no por edición directa.
        unset($data['service_status']);

        $client->update($data);
        if ($newPlan) {
            app(ServiceControl::class)->changePlan($client, $newPlan, 'api');
        }

        return $this->ok(['data' => new ClientResource($client->fresh(self::WITH))], 'Cliente actualizado.');
    }

    /** Estado de cuenta: saldos, cargos abiertos/recientes y pagos de los últimos 12 meses. */
    public function statement(int $id): JsonResponse
    {
        $client = Client::query()->with('plan')->findOrFail($id);
        $billing = app(BillingService::class);
        $since = now()->subMonths(12);
        $charges = $client->charges()->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'partial'])->orWhere('due_date', '>=', $since))
            ->orderByDesc('due_date')->get();
        $payments = $client->payments()->where('status', 'completed')->where('paid_at', '>=', $since)->orderByDesc('paid_at')->get();

        return $this->ok([
            'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->full_name],
            'summary' => [
                'balance' => round((float) $client->balance, 2),
                'credit_balance' => round((float) $client->credit_balance, 2),
                'overdue_amount' => round($client->overdueAmount(), 2),
                'overdue_days' => $billing->overdueDays($client),
                'next_due_date' => $client->next_due_date?->toDateString(),
                'plan' => $client->plan?->name,
            ],
            'charges' => ChargeResource::collection($charges),
            'payments' => PaymentResource::collection($payments),
            'pdf_url' => URL::temporarySignedRoute('api.v1.statement', now()->addDay(), ['client' => $client->id]),
        ]);
    }

    protected function nextCode(): string
    {
        $max = (int) Client::withTrashed()->max(DB::raw('CAST(code AS UNSIGNED)'));

        return (string) max(1001, $max + 1);
    }

    protected function rules(?Client $client = null): array
    {
        $req = $client ? 'sometimes' : 'required';
        $tid = tenant()->id;

        return [
            'code' => ['sometimes', 'string', 'max:30'],
            'first_name' => [$req, 'string', 'max:120'],
            'last_name' => 'sometimes|nullable|string|max:120',
            'phone' => 'sometimes|nullable|string|max:30',
            'whatsapp' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:150',
            'curp' => 'sometimes|nullable|string|max:20',
            'rfc' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:190',
            'references' => 'sometimes|nullable|string|max:190',
            'lat' => 'sometimes|nullable|numeric|between:-90,90',
            'lng' => 'sometimes|nullable|numeric|between:-180,180',
            'zone_id' => ['sometimes', 'nullable', $this->existsInTenant('zones')],
            'community' => 'sometimes|nullable|string|max:120',
            'colonia' => 'sometimes|nullable|string|max:120',
            'plan_id' => [$req, $this->existsInTenant('plans')],
            'installation_date' => 'sometimes|nullable|date',
            'technician_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'collector_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'repeater_id' => ['sometimes', 'nullable', $this->existsInTenant('repeaters')],
            'cpe_device_id' => ['sometimes', 'nullable', $this->existsInTenant('network_devices')],
            'router_device_id' => ['sometimes', 'nullable', $this->existsInTenant('network_devices')],
            'ip_address' => 'sometimes|nullable|ip',
            'mac_address' => ['sometimes', 'nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'pppoe_user' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('clients', 'pppoe_user')->where('tenant_id', $tid)->ignore($client?->id)],
            'pppoe_password' => 'sometimes|nullable|string|max:80',
            'service_status' => ['sometimes', Rule::in(array_keys(config('wisp.client_status')))],
            'billing_day' => 'sometimes|integer|between:1,28',
            'notes' => 'sometimes|nullable|string|max:2000',
            'referral_code' => 'sometimes|nullable|string|max:30',
        ];
    }
}
