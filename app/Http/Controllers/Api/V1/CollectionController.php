<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CashCutResource;
use App\Http\Resources\ChargeResource;
use App\Http\Resources\ClientSummaryResource;
use App\Http\Resources\CollectionVisitResource;
use App\Http\Resources\PaymentResource;
use App\Models\CashCut;
use App\Models\Charge;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use App\Services\Field\CollectionRoute;
use App\Services\Field\FieldTracker;
use App\Support\InputRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * App del cobrador (secciones 26 y 27): clientes pendientes en orden de ruta, visitas,
 * cobros en campo y corte de caja. Misma lógica que «Mi cobranza» en la web (CollectionRoute).
 */
class CollectionController extends ApiController
{
    /** Métodos que puede registrar el cobrador en campo (igual que la web). */
    public const FIELD_METHODS = ['efectivo', 'transferencia', 'deposito', 'tarjeta'];

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

    /**
     * Jornada del cobrador (pantalla principal de la app): resumen del día, corte abierto, ruta
     * y clientes en orden. ?view=pendientes (por visitar, orden de ruta y cercanía) | visitados (hoy)
     * &search= &lat= &lng= (posición actual para ordenar por cercanía).
     */
    public function today(Request $request, CollectionRoute $routes, CashCutService $cuts): JsonResponse
    {
        $data = $this->check($request, [
            'view' => 'nullable|in:pendientes,visitados',
            'search' => 'nullable|string|max:80',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);
        $me = $this->apiUser();
        if (isset($data['lat'], $data['lng'])) {
            app(FieldTracker::class)->updateLocation($me, $data['lat'], $data['lng']);
            $me->refresh();
        }
        $view = $data['view'] ?? 'pendientes';
        $cursor = isset($data['lat'], $data['lng']) ? [(float) $data['lat'], (float) $data['lng']] : null;
        $visitsToday = $routes->visitsToday($me->id);
        $byClient = $visitsToday->keyBy('client_id');
        $route = $routes->todayRoute($me->id);
        $cut = $cuts->openCutFor($me->id, false);
        $summary = $routes->summary($me, $visitsToday);

        $clients = $routes->clients($me, $view, $data['search'] ?? null, $cursor, $visitsToday)
            ->values()->map(fn (Client $c, int $i) => $this->clientRow($c, $i + 1, $byClient->get($c->id)));

        return $this->ok([
            'date' => now()->toDateString(),
            'view' => $view,
            'summary' => $summary,
            'cash_cut' => $cut ? new CashCutResource($cut) : null,
            'route' => $route ? [
                'id' => $route->id,
                'stops_total' => $route->stops->count(),
                'stops_done' => $route->stops->where('status', 'done')->count(),
                'maps_url' => $route->mapsUrl(),
            ] : null,
            'ordered_by' => $route ? 'route' : ($cursor || $me->last_lat ? 'distance' : 'balance'),
            'counts' => ['pendientes' => $summary['pending'], 'visitados' => $visitsToday->count()],
            'data' => $clients,
        ]);
    }

    /** Detalle para cobrar: cargos pendientes, últimos pagos y visitas (historial de la web). */
    public function client(int $id): JsonResponse
    {
        $client = $this->myClient($id)->load(['zone:id,name', 'plan:id,name,price']);
        $billing = app(BillingService::class);

        return $this->ok([
            'data' => $this->clientRow($client) + [
                'overdue_amount' => round($client->overdueAmount(), 2),
                'credit_balance' => round((float) $client->credit_balance, 2),
            ],
            'overdue_days' => $billing->overdueDays($client),
            'charges' => ChargeResource::collection(Charge::query()->where('client_id', $client->id)->open()->orderBy('due_date')->get()),
            'payments' => PaymentResource::collection(Payment::query()->where('client_id', $client->id)->where('status', 'completed')->with('collector')->latest('paid_at')->limit(4)->get()),
            'visits' => CollectionVisitResource::collection(CollectionVisit::query()->where('client_id', $client->id)->with('payment')->latest('visited_at')->limit(5)->get()),
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
            'promise_amount' => 'nullable|numeric|gt:0|max:100000',
            'notes' => 'nullable|string|max:190',
            'evidence' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,heif,pdf',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ], ['promise_amount' => 'monto prometido', 'evidence' => 'evidencia', 'notes' => 'notas']);

        $client = $this->myClient($data['client_id']);
        if ($data['result'] !== 'promesa') {
            unset($data['promise_date'], $data['promise_amount']);
        }
        $visit = $this->createVisit($client, $data['result'], $data, $request);
        app(CollectionRoute::class)->completeStop($this->apiUser()->id, $client->id);

        return $this->ok(['data' => new CollectionVisitResource($visit)], 'Visita registrada: '.config("wisp.visit_results.{$data['result']}.0").'.', 201);
    }

    /** Cobro en campo: pago + visita "pago" + corte de caja abierto del cobrador (si es efectivo). */
    public function payment(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'client_id' => ['required', 'integer', $this->existsInTenant('clients')],
            'amount' => 'required|numeric|min:1|max:100000',
            'method' => ['nullable', Rule::in(self::FIELD_METHODS)],
            'reference' => 'nullable|string|max:120|'.InputRules::TEXT,
            'notes' => 'nullable|string|max:190',
            'evidence' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,heif,pdf',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ], ['evidence' => 'evidencia', 'notes' => 'notas']);

        $client = $this->myClient($data['client_id']);
        $user = $this->apiUser();
        $method = $data['method'] ?? 'efectivo';
        $evidence = $request->hasFile('evidence') ? $request->file('evidence')->store('evidence/'.tenant()->id.'/collection', 'public') : null;

        $due = round((float) $client->balance, 2);

        $payment = DB::transaction(function () use ($client, $data, $user, $evidence, $request, $due, $method) {
            $payment = app(BillingService::class)->registerPayment($client, (float) $data['amount'], $method, array_filter([
                'collector_id' => $user->id,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? 'Cobro en campo (app)',
                'evidence_path' => $evidence,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ], fn ($v) => $v !== null));
            app(CashCutService::class)->attach($payment);
            $this->createVisit($client, 'pago', $data + ['payment_id' => $payment->id, 'evidence_path' => $evidence], $request, $due);
            app(CollectionRoute::class)->completeStop($user->id, $client->id);

            return $payment;
        });

        $cut = CashCut::query()->find($payment->fresh()->cash_cut_id);

        return $this->ok([
            'data' => new PaymentResource($payment->fresh(['client', 'collector'])),
            'client_balance' => round((float) $client->fresh()->balance, 2),
            'cash_cut' => $cut ? ['id' => $cut->id, 'folio' => $cut->folio, 'payments_count' => (int) $cut->payments_count, 'expected_amount' => round((float) $cut->expected_amount, 2)] : null,
        ], "Pago {$payment->folio} registrado: ".money($payment->amount).' de '.$client->full_name.'.', 201);
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
            'data' => $cut ? new CashCutResource($cut) : null,
            'payments' => PaymentResource::collection($payments),
            // Cortes cerrados que el supervisor aún no concilia
            'pending_delivery' => CashCutResource::collection(
                CashCut::query()->where('collector_id', $this->apiUser()->id)->whereNotNull('closed_at')->where('status', '!=', 'reconciled')->latest('closed_at')->get()
            ),
        ]);
    }

    /**
     * Hacer el corte (sección 27): cierra el corte abierto y fija el efectivo esperado; el supervisor
     * registra la entrega y concilia en Cobranza → Cortes de caja. counted_amount = efectivo que
     * cuenta el cobrador (queda en las notas del corte para que el supervisor lo compare).
     */
    public function closeCashCut(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'counted_amount' => 'nullable|numeric|min:0|max:10000000',
            'notes' => 'nullable|string|max:300',
        ], ['counted_amount' => 'efectivo contado', 'notes' => 'notas']);
        $service = app(CashCutService::class);
        $cut = $service->openCutFor($this->apiUser()->id, false);
        if (! $cut) {
            $this->deny('No tienes un corte abierto: aún no registras cobros en efectivo.', 422);
        }
        $service->recompute($cut);
        if ((int) $cut->payments_count === 0) {
            $this->deny('Tu corte no tiene cobros en efectivo.', 422);
        }

        $note = collect([
            isset($data['counted_amount']) ? 'Efectivo contado por el cobrador: '.money($data['counted_amount'])
                .' (diferencia '.money(round((float) $data['counted_amount'] - (float) $cut->expected_amount, 2)).')' : null,
            $data['notes'] ?? null,
        ])->filter()->implode(' · ');
        $service->close($cut, $note ?: null);

        return $this->ok(['data' => new CashCutResource($cut->fresh())],
            "Corte {$cut->folio} cerrado: entrega ".money($cut->fresh()->expected_amount).' al supervisor.');
    }

    /** Historial de cortes del cobrador. */
    public function cashCuts(Request $request)
    {
        return CashCutResource::collection(
            CashCut::query()->where('collector_id', $this->apiUser()->id)->with('reconciledBy')->latest('opened_at')->paginate($this->perPage($request, 20))
        );
    }

    /** Cliente del cobrador (o cualquiera del WISP si tiene billing.manage). */
    protected function myClient(int $id): Client
    {
        $client = Client::query()->findOrFail($id);
        if ((int) $client->collector_id !== (int) $this->apiUser()->id && ! $this->allows('billing.manage')) {
            $this->deny('Este cliente no está asignado a tu cobranza.');
        }

        return $client;
    }

    /** Fila de cliente de «Mi cobranza»: saldo, atraso, plan, zona, dirección, ruta, cercanía y visita de hoy. */
    protected function clientRow(Client $c, ?int $position = null, ?CollectionVisit $visit = null): array
    {
        $oldest = $c->oldest_due ?? Charge::query()->where('client_id', $c->id)->whereIn('status', ['pending', 'partial'])->min('due_date');
        $days = $oldest && Carbon::parse($oldest)->lt(today()) ? (int) Carbon::parse($oldest)->diffInDays(today()) : 0;
        $nav = $c->lat && $c->lng
            ? 'https://www.google.com/maps/dir/?api=1&destination='.$c->lat.','.$c->lng
            : 'https://www.google.com/maps/search/?api=1&query='.urlencode(trim($c->address.' '.$c->colonia.' '.$c->community));

        return array_merge((new ClientSummaryResource($c))->resolve(), [
            'position' => $position,
            'whatsapp' => $c->whatsapp,
            'colonia' => $c->colonia,
            'references' => $c->references,
            'plan' => $c->plan ? ['id' => $c->plan->id, 'name' => $c->plan->name] : null,
            'zone' => $c->zone ? ['id' => $c->zone->id, 'name' => $c->zone->name] : null,
            'overdue_days' => $days,
            'next_due_date' => $c->next_due_date?->toDateString(),
            'route_seq' => $c->route_seq ?? null,
            'distance_km' => $c->distance_km ?? null,
            'navigation_url' => $nav,
            'visit_today' => $visit ? new CollectionVisitResource($visit) : null,
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
