<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ClientSummaryResource;
use App\Http\Resources\CollectionVisitResource;
use App\Http\Resources\PaymentResource;
use App\Models\CashCut;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use App\Services\Field\FieldTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * App del cobrador (secciones 26 y 27): clientes pendientes, visitas y cobro en efectivo.
 */
class CollectionController extends ApiController
{
    /** Clientes con saldo asignados al cobrador (o todos con ?all=1 si tiene billing.view). */
    public function pending(Request $request)
    {
        $user = $this->apiUser();
        $all = $request->boolean('all') && $this->allows('billing.view');
        $q = Client::query()->billable()->where('balance', '>', 0)
            ->when(! $all, fn ($q) => $q->where('collector_id', $user->id))
            ->when($request->query('zone_id'), fn ($q, $v) => $q->where('zone_id', $v))
            ->search($request->query('search'))
            ->orderBy('next_due_date')->orderByDesc('balance');

        $page = $q->paginate($this->perPage($request, 50));
        $ids = $page->getCollection()->pluck('id');
        $lastVisits = CollectionVisit::query()->whereIn('client_id', $ids)
            ->whereIn('id', CollectionVisit::query()->selectRaw('MAX(id)')->whereIn('client_id', $ids)->groupBy('client_id'))
            ->get()->keyBy('client_id');
        $billing = app(BillingService::class);

        $page->getCollection()->transform(fn (Client $c) => array_merge((new ClientSummaryResource($c))->resolve(), [
            'overdue_amount' => round($c->overdueAmount(), 2),
            'overdue_days' => $billing->overdueDays($c),
            'next_due_date' => $c->next_due_date?->toDateString(),
            'navigation_url' => $c->lat && $c->lng ? 'https://www.google.com/maps/dir/?api=1&destination='.$c->lat.','.$c->lng : null,
            'last_visit' => ($v = $lastVisits->get($c->id)) ? new CollectionVisitResource($v) : null,
        ]));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => [
                'clients' => $page->total(),
                'amount' => round((float) (clone $q)->reorder()->sum('balance'), 2),
            ],
        ]);
    }

    /** Historial de visitas del cliente. */
    public function history(int $clientId)
    {
        $client = Client::query()->findOrFail($clientId);

        return CollectionVisitResource::collection(
            CollectionVisit::query()->where('client_id', $client->id)->with('payment')->latest('visited_at')->limit(50)->get()
        );
    }

    /** Registra una visita sin pago: no localizado, promesa, rechazo, domicilio cerrado. */
    public function visit(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'client_id' => ['required', 'integer', $this->existsInTenant('clients')],
            'result' => ['required', Rule::in(array_diff(array_keys(config('wisp.visit_results')), ['pago']))],
            'promise_date' => 'required_if:result,promesa|nullable|date|after_or_equal:today',
            'promise_amount' => 'nullable|numeric|gt:0',
            'notes' => 'nullable|string|max:190',
            'evidence' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ], ['promise_amount' => 'monto prometido', 'evidence' => 'evidencia', 'notes' => 'notas']);

        $client = Client::query()->findOrFail($data['client_id']);
        $visit = $this->createVisit($client, $data['result'], $data, $request);

        return $this->ok(['data' => new CollectionVisitResource($visit)], 'Visita registrada.', 201);
    }

    /** Cobro en efectivo en campo: pago + visita "pago" + corte de caja abierto del cobrador. */
    public function payment(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'client_id' => ['required', 'integer', $this->existsInTenant('clients')],
            'amount' => 'required|numeric|gt:0|max:1000000',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:190',
            'evidence' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ], ['evidence' => 'evidencia', 'notes' => 'notas']);

        $client = Client::query()->findOrFail($data['client_id']);
        $user = $this->apiUser();
        $evidence = $request->hasFile('evidence') ? $request->file('evidence')->store('evidence/'.tenant()->id.'/collection', 'public') : null;

        $due = round((float) $client->balance, 2);

        $payment = DB::transaction(function () use ($client, $data, $user, $evidence, $request, $due) {
            $payment = app(BillingService::class)->registerPayment($client, (float) $data['amount'], 'efectivo', array_filter([
                'collector_id' => $user->id,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? 'Cobro en campo (app)',
                'evidence_path' => $evidence,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ], fn ($v) => $v !== null));
            app(CashCutService::class)->attach($payment);
            $this->createVisit($client, 'pago', $data + ['payment_id' => $payment->id, 'evidence_path' => $evidence], $request, $due);

            return $payment;
        });

        $cut = CashCut::query()->find($payment->fresh()->cash_cut_id);

        return $this->ok([
            'data' => new PaymentResource($payment->fresh(['client', 'collector'])),
            'client_balance' => round((float) $client->fresh()->balance, 2),
            'cash_cut' => $cut ? ['id' => $cut->id, 'folio' => $cut->folio, 'payments_count' => (int) $cut->payments_count, 'expected_amount' => round((float) $cut->expected_amount, 2)] : null,
        ], "Cobro {$payment->folio} registrado.", 201);
    }

    /** Corte de caja abierto del cobrador. */
    public function cashCut(): JsonResponse
    {
        $cut = app(CashCutService::class)->openCutFor($this->apiUser()->id, false);
        $payments = $cut ? Payment::query()->where('cash_cut_id', $cut->id)->with('client')->latest('paid_at')->get() : collect();

        return $this->ok([
            'cash_cut' => $cut ? [
                'id' => $cut->id, 'folio' => $cut->folio, 'status' => $cut->status, 'opened_at' => $cut->opened_at?->toIso8601String(),
                'payments_count' => (int) $cut->payments_count, 'expected_amount' => round((float) $cut->expected_amount, 2),
            ] : null,
            'payments' => PaymentResource::collection($payments),
        ]);
    }

    protected function createVisit(Client $client, string $result, array $data, Request $request, ?float $amountDue = null): CollectionVisit
    {
        $evidence = $data['evidence_path'] ?? ($request->hasFile('evidence') && $result !== 'pago'
            ? $request->file('evidence')->store('evidence/'.tenant()->id.'/collection', 'public') : null);
        if (isset($data['lat'], $data['lng'])) {
            app(FieldTracker::class)->updateLocation($this->apiUser(), $data['lat'], $data['lng']);
        }

        return CollectionVisit::create([
            'client_id' => $client->id,
            'collector_id' => $this->apiUser()->id,
            'result' => $result,
            'amount_due' => $amountDue ?? round((float) $client->balance, 2),
            'promise_date' => $data['promise_date'] ?? null,
            'promise_amount' => $data['promise_amount'] ?? null,
            'payment_id' => $data['payment_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'evidence_path' => $evidence,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'visited_at' => now(),
        ]);
    }
}
