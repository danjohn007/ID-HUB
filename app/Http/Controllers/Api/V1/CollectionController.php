<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\ApiIdempotency;
use App\Http\Resources\ClientSummaryResource;
use App\Http\Resources\CollectionVisitResource;
use App\Http\Resources\PaymentResource;
use App\Models\Attachment;
use App\Models\CashCut;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use App\Services\Field\FieldTracker;
use App\Support\Catalog;
use App\Support\Evidence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * App del cobrador (secciones 26 y 27): clientes pendientes, visitas, cobro en efectivo y corte de caja.
 *
 * Cobros y visitas aceptan una clave de idempotencia (encabezado Idempotency-Key o campo client_uuid):
 * un reintento con la misma clave devuelve el registro original (200) en lugar de duplicarlo.
 * La evidencia se guarda en el disco privado con la fecha/hora y el GPS de la captura.
 */
class CollectionController extends ApiController
{
    /** Fecha/hora y GPS del momento de la foto de evidencia (enviados por la app). */
    protected const EVIDENCE_RULES = [
        'evidence_taken_at' => 'nullable|date',
        'evidence_lat' => 'nullable|numeric|between:-90,90',
        'evidence_lng' => 'nullable|numeric|between:-180,180',
    ];

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
            'navigation_url' => $c->lat && $c->lng ? 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='.$c->lat.','.$c->lng : null,
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
        $key = ApiIdempotency::key($request);
        if ($key && ($existing = CollectionVisit::query()->where('idempotency_key', $key)->first())) {
            return $this->ok(['data' => new CollectionVisitResource($existing->load('payment')), 'replayed' => true], 'Visita registrada.');
        }

        $data = $this->check($request, [
            'client_id' => ['required', 'integer', $this->existsInTenant('clients')],
            'result' => ['required', Rule::in(array_diff(array_keys(config('wisp.visit_results')), ['pago']))],
            'promise_date' => 'required_if:result,promesa|nullable|date|after_or_equal:today',
            'promise_amount' => 'nullable|numeric|gt:0',
            'notes' => 'nullable|string|max:190',
            'evidence' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ] + self::EVIDENCE_RULES, ['promise_amount' => 'monto prometido', 'evidence' => 'evidencia', 'notes' => 'notas']);

        $client = Client::query()->findOrFail($data['client_id']);
        $evidence = $this->storeEvidence($request->file('evidence'), 'visits');
        try {
            $visit = DB::transaction(function () use ($client, $data, $evidence, $key) {
                $visit = $this->createVisit($client, $data['result'], $data + ['evidence_path' => $evidence, 'idempotency_key' => $key]);
                $this->evidenceAttachment($visit, 'collection_visit', 'otro', $evidence, $data);

                return $visit;
            });
        } catch (UniqueConstraintViolationException) {
            // Reintento simultáneo con la misma clave: se devuelve la visita que se registró primero.
            Evidence::delete($evidence);
            $existing = CollectionVisit::query()->where('idempotency_key', $key)->firstOrFail();

            return $this->ok(['data' => new CollectionVisitResource($existing), 'replayed' => true], 'Visita registrada.');
        }

        return $this->ok(['data' => new CollectionVisitResource($visit)], 'Visita registrada.', 201);
    }

    /** Cobro en efectivo en campo: pago + visita "pago" + corte de caja abierto del cobrador. */
    public function payment(Request $request): JsonResponse
    {
        $key = ApiIdempotency::key($request);
        if ($key && ($existing = Payment::query()->where('idempotency_key', $key)->first())) {
            return $this->paymentResponse($existing, 200, true);
        }

        $data = $this->check($request, [
            'client_id' => ['required', 'integer', $this->existsInTenant('clients')],
            'amount' => 'required|numeric|gt:0|max:1000000',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:190',
            'evidence' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ] + self::EVIDENCE_RULES, ['evidence' => 'evidencia', 'notes' => 'notas']);

        $client = Client::query()->findOrFail($data['client_id']);
        $user = $this->apiUser();
        $evidence = $this->storeEvidence($request->file('evidence'), 'collection');

        $due = round((float) $client->balance, 2);

        try {
            $payment = DB::transaction(function () use ($client, $data, $user, $evidence, $due, $key) {
                $payment = app(BillingService::class)->registerPayment($client, (float) $data['amount'], 'efectivo', array_filter([
                    'idempotency_key' => $key,
                    'collector_id' => $user->id,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? 'Cobro en campo (app)',
                    'evidence_path' => $evidence,
                    'lat' => $data['lat'] ?? null,
                    'lng' => $data['lng'] ?? null,
                ], fn ($v) => $v !== null));
                app(CashCutService::class)->attach($payment);
                $this->createVisit($client, 'pago', $data + ['payment_id' => $payment->id, 'evidence_path' => $evidence], $due);
                $this->evidenceAttachment($payment, 'payment', 'comprobante', $evidence, $data);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            // Reintento simultáneo con la misma clave: el otro request ya registró el cobro.
            Evidence::delete($evidence);

            return $this->paymentResponse(Payment::query()->where('idempotency_key', $key)->firstOrFail(), 200, true);
        }

        return $this->paymentResponse($payment, 201);
    }

    /** Corte de caja abierto del cobrador. */
    public function cashCut(): JsonResponse
    {
        $cut = app(CashCutService::class)->openCutFor($this->apiUser()->id, false);
        $payments = $cut ? Payment::query()->where('cash_cut_id', $cut->id)->with('client')->latest('paid_at')->get() : collect();

        return $this->ok([
            'cash_cut' => $cut ? $this->cutData($cut) : null,
            'payments' => PaymentResource::collection($payments),
            'can_register_delivery' => $this->allows('cash.reconcile'),
        ]);
    }

    /** Historial de cortes de caja del cobrador (más recientes primero). */
    public function cashCuts(Request $request): JsonResponse
    {
        $page = CashCut::query()->where('collector_id', $this->apiUser()->id)
            ->orderByDesc('opened_at')->orderByDesc('id')->paginate($this->perPage($request, 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CashCut $c) => $this->cutData($c))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * "Entregar corte": el cobrador cierra SU corte abierto con CashCutService::close (el mismo cierre
     * del panel: asigna los cobros pendientes y fija el importe esperado). Registrar el efectivo
     * recibido y conciliar sigue siendo del supervisor (cash.reconcile, igual que en el panel): con ese
     * permiso puede enviarse delivered_amount para registrar la entrega en el mismo paso.
     */
    public function closeCashCut(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'delivered_amount' => 'nullable|numeric|min:0|max:10000000',
            'notes' => 'nullable|string|max:300',
        ], ['delivered_amount' => 'efectivo entregado', 'notes' => 'notas']);

        $service = app(CashCutService::class);
        $userId = $this->apiUser()->id;
        $cut = $service->openCutFor($userId, false);
        if (! $cut) {
            // Reintento de un cierre ya hecho (bandeja sin conexión): se devuelve el corte recién cerrado.
            $last = CashCut::query()->where('collector_id', $userId)->where('closed_at', '>=', now()->subMinutes(15))->latest('closed_at')->first();
            if ($last) {
                return $this->ok(['data' => $this->cutData($last)], "El corte {$last->folio} ya estaba cerrado.");
            }
            $this->invalidCut('No tienes un corte de caja abierto.');
        }
        $service->sweep($cut);
        if ((int) $cut->payments_count === 0) {
            $this->invalidCut('Tu corte no tiene cobros por entregar.');
        }

        $cut = $service->close($cut);
        $message = "Corte {$cut->folio} cerrado: {$cut->payments_count} cobros, esperado ".money($cut->expected_amount).'. Entrega el efectivo a tu supervisor.';
        if (isset($data['delivered_amount']) && $this->allows('cash.reconcile')) {
            $cut = $service->deliver($cut, (float) $data['delivered_amount'], $data['notes'] ?? null);
            $diff = (float) $cut->difference;
            $message = "Entrega registrada en {$cut->folio}".(abs($diff) > 0.009 ? '. Diferencia: '.money($diff) : '. Sin diferencias');
        }

        return $this->ok(['data' => $this->cutData($cut->refresh())], $message);
    }

    protected function createVisit(Client $client, string $result, array $data, ?float $amountDue = null): CollectionVisit
    {
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
            'evidence_path' => $data['evidence_path'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'visited_at' => now(),
        ]);
    }

    protected function storeEvidence(?UploadedFile $file, string $folder): ?string
    {
        return $file ? Evidence::store($file, 'evidence/'.tenant()->id.'/'.$folder) : null;
    }

    /** Registro de la evidencia (fecha/hora y GPS del momento de la foto) ligado al cobro o a la visita. */
    protected function evidenceAttachment(Payment|CollectionVisit $model, string $type, string $kind, ?string $path, array $data): void
    {
        if (! $path) {
            return;
        }
        $lat = $data['evidence_lat'] ?? $data['lat'] ?? null;
        $lng = $data['evidence_lng'] ?? $data['lng'] ?? null;
        $file = request()->file('evidence');
        Attachment::create([
            'attachable_type' => $type,
            'attachable_id' => $model->id,
            'kind' => $kind,
            'path' => $path,
            'original_name' => mb_substr((string) $file?->getClientOriginalName(), 0, 190) ?: basename($path),
            'mime' => $file?->getMimeType(),
            'lat' => $lat !== null && $lng !== null ? $lat : null,
            'lng' => $lat !== null && $lng !== null ? $lng : null,
            'taken_at' => Evidence::takenAt($data['evidence_taken_at'] ?? null),
            'uploaded_by' => $this->apiUser()->id,
        ]);
    }

    protected function paymentResponse(Payment $payment, int $status, bool $replayed = false): JsonResponse
    {
        $payment = $payment->fresh(['client', 'collector']);
        $cut = $payment->cash_cut_id ? CashCut::query()->find($payment->cash_cut_id) : null;

        return $this->ok([
            'data' => new PaymentResource($payment),
            'client_balance' => round((float) $payment->client?->balance, 2),
            'cash_cut' => $cut ? ['id' => $cut->id, 'folio' => $cut->folio, 'payments_count' => (int) $cut->payments_count, 'expected_amount' => round((float) $cut->expected_amount, 2)] : null,
        ] + ($replayed ? ['replayed' => true] : []), "Cobro {$payment->folio} registrado.", $status);
    }

    protected function cutData(CashCut $cut): array
    {
        $status = $cut->displayStatus();

        return [
            'id' => $cut->id,
            'folio' => $cut->folio,
            'status' => ['value' => $status, 'label' => Catalog::label('cash_cut_status', $status), 'color' => Catalog::color('cash_cut_status', $status)],
            'opened_at' => $cut->opened_at?->toIso8601String(),
            'closed_at' => $cut->closed_at?->toIso8601String(),
            'payments_count' => (int) $cut->payments_count,
            'expected_amount' => round((float) $cut->expected_amount, 2),
            'delivered_amount' => $cut->delivered_amount !== null ? round((float) $cut->delivered_amount, 2) : null,
            'difference' => $cut->difference !== null ? round((float) $cut->difference, 2) : null,
            'reconciled_at' => $cut->reconciled_at?->toIso8601String(),
        ];
    }

    protected function invalidCut(string $message): never
    {
        abort(response()->json(['message' => $message, 'errors' => ['cash_cut' => [$message]]], 422));
    }
}
