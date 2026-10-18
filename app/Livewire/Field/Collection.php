<?php

namespace App\Livewire\Field;

use App\Livewire\Concerns\Toasts;
use App\Models\Charge;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use App\Services\Field\CollectionRoute;
use App\Support\InputRules;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * App de campo del cobrador (sección 26): clientes pendientes en orden de ruta,
 * registro de pagos (efectivo por defecto, evidencia y GPS), visitas (no localizado,
 * promesa, rechazo, domicilio cerrado), navegación y resumen del día / corte abierto.
 */
#[Title('Mi cobranza')]
class Collection extends Component
{
    use Toasts, WithFileUploads;

    #[Url(as: 'ver', except: 'pendientes')]
    public string $view = 'pendientes';

    public string $search = '';

    public ?string $myLat = null;

    public ?string $myLng = null;

    public ?int $expanded = null;

    public ?int $clientId = null;

    public bool $showPay = false;

    public bool $showVisit = false;

    public array $payForm = ['amount' => '', 'method' => 'efectivo', 'reference' => '', 'notes' => '', 'lat' => null, 'lng' => null];

    public array $visitForm = ['result' => 'no_localizado', 'promise_date' => '', 'promise_amount' => '', 'notes' => '', 'lat' => null, 'lng' => null];

    public $payEvidence;

    public $visitEvidence;

    public ?int $lastPaymentId = null;

    public function mount(): void
    {
        $u = auth()->user();
        if ($u->last_lat && $u->last_location_at && $u->last_location_at->isToday()) {
            $this->myLat = (string) $u->last_lat;
            $this->myLng = (string) $u->last_lng;
        }
    }

    /** Al obtener GPS del dispositivo se guarda como ubicación del cobrador (mapa de supervisión). */
    public function updatedMyLng(): void
    {
        if (is_numeric($this->myLat) && is_numeric($this->myLng)) {
            auth()->user()->forceFill(['last_lat' => $this->myLat, 'last_lng' => $this->myLng, 'last_location_at' => now()])->saveQuietly();
        }
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    protected function myClient(int $id): Client
    {
        return Client::query()->where('collector_id', auth()->id())->findOrFail($id);
    }

    public function openPay(int $id): void
    {
        $client = $this->myClient($id);
        $this->clientId = $client->id;
        $this->payForm = ['amount' => (string) round((float) $client->balance, 2), 'method' => 'efectivo', 'reference' => '', 'notes' => '', 'lat' => $this->myLat, 'lng' => $this->myLng];
        $this->payEvidence = null;
        $this->lastPaymentId = null;
        $this->resetValidation();
        $this->showPay = true;
        $this->dispatch('locate-form');
    }

    public function openVisit(int $id, string $result = 'no_localizado'): void
    {
        $client = $this->myClient($id);
        $this->clientId = $client->id;
        $this->visitForm = ['result' => $result, 'promise_date' => now()->addDays(3)->toDateString(), 'promise_amount' => (string) round((float) $client->balance, 2), 'notes' => '', 'lat' => $this->myLat, 'lng' => $this->myLng];
        $this->visitEvidence = null;
        $this->resetValidation();
        $this->showVisit = true;
        $this->dispatch('locate-form');
    }

    public function savePayment(BillingService $billing, CashCutService $cuts): void
    {
        $this->authorize('collection.field');
        $this->validate([
            'payForm.amount' => 'required|numeric|min:1|max:100000',
            'payForm.method' => 'required|in:efectivo,transferencia,deposito,tarjeta',
            'payForm.reference' => 'nullable|string|max:120|'.InputRules::TEXT,
            'payForm.notes' => 'nullable|string|max:190',
            'payForm.lat' => 'nullable|numeric|between:-90,90',
            'payForm.lng' => 'nullable|numeric|between:-180,180',
            'payEvidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,heif,pdf|max:8192',
        ], [], ['payForm.amount' => 'importe', 'payForm.method' => 'método', 'payForm.reference' => 'referencia', 'payForm.notes' => 'notas', 'payEvidence' => 'foto de evidencia']);

        $client = $this->myClient($this->clientId);
        $due = (float) $client->balance;
        $evidence = $this->payEvidence ? $this->payEvidence->store('payments/'.tenant()->id.'/'.now()->format('Y/m'), 'public') : null;
        $lat = is_numeric($this->payForm['lat']) ? $this->payForm['lat'] : null;
        $lng = is_numeric($this->payForm['lng']) ? $this->payForm['lng'] : null;

        $payment = DB::transaction(function () use ($billing, $cuts, $client, $due, $evidence, $lat, $lng) {
            $payment = $billing->registerPayment($client, (float) $this->payForm['amount'], $this->payForm['method'], [
                'collector_id' => auth()->id(),
                'reference' => $this->payForm['reference'] ?: null,
                'notes' => $this->payForm['notes'] ?: 'Cobro en campo',
                'evidence_path' => $evidence,
                'lat' => $lat,
                'lng' => $lng,
            ]);
            $cuts->attach($payment);
            CollectionVisit::create([
                'client_id' => $client->id, 'collector_id' => auth()->id(), 'result' => 'pago', 'amount_due' => $due,
                'payment_id' => $payment->id, 'notes' => $this->payForm['notes'] ?: null, 'evidence_path' => $evidence,
                'lat' => $lat, 'lng' => $lng, 'visited_at' => now(),
            ]);
            app(CollectionRoute::class)->completeStop(auth()->id(), $client->id);

            return $payment;
        });

        $this->showPay = false;
        $this->lastPaymentId = $payment->id;
        $this->toast("Pago {$payment->folio} registrado: ".money($payment->amount).' de '.$client->full_name);
    }

    public function saveVisit(): void
    {
        $this->authorize('collection.field');
        $this->validate([
            'visitForm.result' => 'required|in:no_localizado,promesa,rechazo,cerrado',
            'visitForm.promise_date' => 'exclude_unless:visitForm.result,promesa|required|date|after_or_equal:today',
            'visitForm.promise_amount' => 'exclude_unless:visitForm.result,promesa|nullable|numeric|min:1|max:100000',
            'visitForm.notes' => 'nullable|string|max:190',
            'visitForm.lat' => 'nullable|numeric|between:-90,90',
            'visitForm.lng' => 'nullable|numeric|between:-180,180',
            'visitEvidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,heif,pdf|max:8192',
        ], [], ['visitForm.result' => 'resultado', 'visitForm.promise_date' => 'fecha de promesa', 'visitForm.promise_amount' => 'monto prometido', 'visitEvidence' => 'foto de evidencia']);

        $client = $this->myClient($this->clientId);
        $isPromise = $this->visitForm['result'] === 'promesa';
        CollectionVisit::create([
            'client_id' => $client->id,
            'collector_id' => auth()->id(),
            'result' => $this->visitForm['result'],
            'amount_due' => (float) $client->balance,
            'promise_date' => $isPromise ? $this->visitForm['promise_date'] : null,
            'promise_amount' => $isPromise && $this->visitForm['promise_amount'] !== '' ? $this->visitForm['promise_amount'] : null,
            'notes' => $this->visitForm['notes'] ?: null,
            'evidence_path' => $this->visitEvidence ? $this->visitEvidence->store('visits/'.tenant()->id.'/'.now()->format('Y/m'), 'public') : null,
            'lat' => is_numeric($this->visitForm['lat']) ? $this->visitForm['lat'] : null,
            'lng' => is_numeric($this->visitForm['lng']) ? $this->visitForm['lng'] : null,
            'visited_at' => now(),
        ]);
        app(CollectionRoute::class)->completeStop(auth()->id(), $client->id);
        $this->showVisit = false;
        $this->toast('Visita registrada: '.\App\Support\Catalog::label('visit_results', $this->visitForm['result']));
    }

    public function render(CashCutService $cuts, CollectionRoute $routes)
    {
        $me = auth()->user();
        $route = $routes->todayRoute($me->id);
        $visitsToday = $routes->visitsToday($me->id);
        $cursor = is_numeric($this->myLat) ? [(float) $this->myLat, (float) $this->myLng] : null;
        $clients = $routes->clients($me, $this->view, $this->search, $cursor, $visitsToday);

        $history = collect();
        if ($this->expanded) {
            $history = [
                'visits' => CollectionVisit::query()->where('client_id', $this->expanded)->with('collector:id,name')->latest('visited_at')->limit(5)->get(),
                'payments' => Payment::query()->where('client_id', $this->expanded)->where('status', 'completed')->latest('paid_at')->limit(4)->get(),
                'charges' => Charge::query()->where('client_id', $this->expanded)->open()->orderBy('due_date')->get(),
            ];
        }

        $cut = $cuts->openCutFor($me->id, false);

        return view('livewire.field.collection', [
            'clients' => $clients,
            'route' => $route,
            'visitsToday' => $visitsToday->keyBy('client_id'),
            'history' => $history,
            'summary' => $routes->summary($me, $visitsToday),
            'cut' => $cut,
            'actionClient' => $this->clientId ? Client::find($this->clientId) : null,
            'lastPayment' => $this->lastPaymentId ? Payment::find($this->lastPaymentId) : null,
        ]);
    }
}
