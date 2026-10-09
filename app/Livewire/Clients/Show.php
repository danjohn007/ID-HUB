<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\Toasts;
use App\Models\AuditLog;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\BillingService;
use App\Services\Messaging\MessageService;
use App\Services\MikroTik\MikroTikService;
use App\Services\ReferralService;
use App\Services\RiskService;
use App\Services\ServiceControl;
use App\Services\TicketService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Show extends Component
{
    use Toasts, WithFileUploads;

    public Client $client;

    #[Url]
    public string $tab = 'resumen';

    // Modales
    public bool $showPayment = false;

    public bool $showCharge = false;

    public bool $showPlan = false;

    public bool $showSuspend = false;

    public bool $showCancel = false;

    public bool $showTicket = false;

    public bool $showMessage = false;

    public array $payment = ['amount' => '', 'method' => 'efectivo', 'reference' => '', 'notes' => '', 'notify' => true];

    public array $charge = ['type' => 'otro', 'description' => '', 'amount' => '', 'due_date' => ''];

    public ?int $newPlanId = null;

    public string $reason = '';

    public array $ticket = ['category' => 'sin_internet', 'priority' => 'media', 'subject' => '', 'description' => ''];

    public array $message = ['channel' => 'whatsapp', 'body' => ''];

    public $evidence;

    public string $evidenceKind = 'instalacion';

    public ?array $connection = null;

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->newPlanId = $client->plan_id;
        $this->payment['amount'] = $client->balance > 0 ? (string) $client->balance : (string) ($client->plan?->price ?? '');
        $this->charge['due_date'] = now()->addDays(5)->toDateString();
    }

    // ------------------------------------------------------------ Cobranza

    public function registerPayment(BillingService $billing): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'payment.amount' => 'required|numeric|min:1|max:1000000',
            'payment.method' => 'required|in:'.implode(',', array_keys(config('wisp.payment_methods'))),
            'payment.reference' => 'nullable|string|max:120',
            'payment.notes' => 'nullable|string|max:190',
        ], [], ['payment.amount' => 'importe', 'payment.method' => 'método']);

        $p = $billing->registerPayment($this->client, (float) $this->payment['amount'], $this->payment['method'], [
            'reference' => $this->payment['reference'] ?: null,
            'notes' => $this->payment['notes'] ?: null,
            'notify' => (bool) $this->payment['notify'],
            'collector_id' => $this->payment['method'] === 'efectivo' && auth()->user()->role === 'cobrador' ? auth()->id() : null,
        ]);
        $this->showPayment = false;
        $this->client->refresh();
        $this->payment = ['amount' => (string) max(0, (float) $this->client->balance), 'method' => 'efectivo', 'reference' => '', 'notes' => '', 'notify' => true];
        $this->toast("Pago {$p->folio} registrado por ".money($p->amount));
    }

    public function addCharge(BillingService $billing): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'charge.type' => 'required|in:'.implode(',', array_keys(config('wisp.charge_types'))),
            'charge.description' => 'required|string|max:190',
            'charge.amount' => 'required|numeric|min:1',
            'charge.due_date' => 'required|date',
        ], [], ['charge.description' => 'concepto', 'charge.amount' => 'importe']);
        $billing->createCharge($this->client, $this->charge['type'], $this->charge['description'], (float) $this->charge['amount'], \Carbon\Carbon::parse($this->charge['due_date']));
        $this->showCharge = false;
        $this->charge = ['type' => 'otro', 'description' => '', 'amount' => '', 'due_date' => now()->addDays(5)->toDateString()];
        $this->client->refresh();
        $this->toast('Cargo agregado');
    }

    public function cancelCharge(int $id, BillingService $billing): void
    {
        $this->authorize('billing.manage');
        $billing->cancelCharge(Charge::where('client_id', $this->client->id)->findOrFail($id), 'Cancelado desde ficha');
        $this->client->refresh();
        $this->toast('Cargo cancelado');
    }

    public function cancelPayment(int $id, BillingService $billing): void
    {
        $this->authorize('billing.manage');
        $billing->cancelPayment(Payment::where('client_id', $this->client->id)->findOrFail($id), 'Cancelado desde ficha');
        $this->client->refresh();
        $this->toast('Pago cancelado', 'warning');
    }

    public function sendPaymentLink(BillingService $billing, MessageService $messages): void
    {
        $link = $billing->paymentLinkFor($this->client);
        if (! $link) {
            $this->toast('El cliente no tiene saldo pendiente', 'warning');

            return;
        }
        $messages->notify($this->client, 'payment_link', ['link_pago' => $link->url(), 'monto' => money($link->amount)]);
        $this->toast('Enlace de pago enviado por WhatsApp');
    }

    // ------------------------------------------------------------ Servicio

    public function suspend(ServiceControl $service): void
    {
        $this->authorize('service.control');
        $event = $service->suspend($this->client, $this->reason ?: 'Suspensión manual');
        $this->showSuspend = false;
        $this->reason = '';
        $this->client->refresh();
        $this->toast('Servicio suspendido. '.$event->network_result, $event->network_applied ? 'success' : 'warning');
    }

    public function reconnect(ServiceControl $service): void
    {
        $this->authorize('service.control');
        $event = $service->reconnect($this->client, 'Reconexión manual', 'manual_nofee');
        $this->client->refresh();
        $this->toast('Servicio reactivado. '.$event->network_result, $event->network_applied ? 'success' : 'warning');
    }

    public function activate(ServiceControl $service): void
    {
        $this->authorize('service.control');
        if (tenant()->exceedsLimit('clients', ['service_status' => 'activo'], $this->client->only('service_status'))) {
            $this->toast(tenant()->limitMessage('clients'), 'error');

            return;
        }
        $event = $service->activate($this->client);
        $this->client->refresh();
        $this->toast('Servicio activado. '.$event->network_result);
    }

    public function changePlan(ServiceControl $service): void
    {
        $this->authorize('clients.manage');
        $plan = Plan::findOrFail($this->newPlanId);
        $event = $service->changePlan($this->client, $plan);
        $this->showPlan = false;
        $this->client->refresh();
        $this->toast("Plan cambiado a {$plan->name}. ".$event->network_result);
    }

    public function cancelService(ServiceControl $service): void
    {
        $this->authorize('clients.manage');
        $this->validate(['reason' => 'required|string|min:3|max:190'], [], ['reason' => 'motivo']);
        $service->cancel($this->client, $this->reason);
        $this->showCancel = false;
        $this->client->refresh();
        $this->toast('Servicio cancelado', 'warning');
    }

    public function testConnection(ServiceControl $service): void
    {
        $router = $service->routerFor($this->client);
        if (! $router) {
            $this->connection = ['error' => 'El cliente no tiene un MikroTik asignado'];

            return;
        }
        try {
            $this->connection = MikroTikService::for($router)->clientConnection($this->client) + ['router' => $router->name];
            if ($this->connection['online']) {
                $this->client->forceFill(['online' => true, 'last_online_at' => now()] + ($this->connection['signal'] ? ['signal_dbm' => $this->connection['signal']] : []))->saveQuietly();
            }
        } catch (\Throwable $e) {
            $this->connection = ['error' => $e->getMessage()];
        }
    }

    public function recalculateRisk(RiskService $risk): void
    {
        $risk->refresh($this->client);
        $this->client->refresh();
        $this->toast('Indicador de riesgo actualizado');
    }

    // ------------------------------------------------------------ Tickets / mensajes / evidencias

    public function createTicket(TicketService $tickets)
    {
        $this->authorize('tickets.manage');
        $this->validate([
            'ticket.category' => 'required', 'ticket.priority' => 'required', 'ticket.subject' => 'required|string|max:190', 'ticket.description' => 'nullable|string|max:2000',
        ], [], ['ticket.subject' => 'asunto']);
        $t = $tickets->create($this->ticket + ['client_id' => $this->client->id, 'repeater_id' => $this->client->repeater_id]);
        $this->showTicket = false;
        $this->ticket = ['category' => 'sin_internet', 'priority' => 'media', 'subject' => '', 'description' => ''];
        $this->toast("Ticket {$t->folio} creado");
    }

    public function sendMessage(MessageService $messages): void
    {
        $this->validate(['message.channel' => 'required|in:whatsapp,sms,email', 'message.body' => 'required|string|max:1000'], [], ['message.body' => 'mensaje']);
        $to = match ($this->message['channel']) {
            'email' => $this->client->email,
            'sms' => $messages->phone($this->client->phone ?: $this->client->whatsapp),
            default => $messages->phone($this->client->whatsapp ?: $this->client->phone),
        };
        if (! $to) {
            $this->addError('message.channel', 'El cliente no tiene dato de contacto para este canal.');

            return;
        }
        $body = $messages->render($this->message['body'], $messages->clientVars($this->client));
        $messages->queue($this->client->tenant, $this->message['channel'], $to, $body, tenant()->name, $this->client, 'manual');
        $this->showMessage = false;
        $this->message['body'] = '';
        $this->toast('Mensaje enviado');
    }

    public function uploadEvidence(): void
    {
        $this->validate(['evidence' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,pdf'], [], ['evidence' => 'archivo']);
        $path = $this->evidence->store('evidences/'.tenant()->id, 'public');
        $this->client->attachments()->create([
            'tenant_id' => $this->client->tenant_id, 'kind' => $this->evidenceKind, 'path' => $path,
            'original_name' => $this->evidence->getClientOriginalName(), 'mime' => $this->evidence->getMimeType(),
            'taken_at' => now(), 'uploaded_by' => auth()->id(),
        ]);
        $this->reset('evidence');
        $this->toast('Archivo agregado');
    }

    public function render()
    {
        $c = $this->client->load(['plan', 'zone', 'repeater.router', 'router', 'cpe', 'technician', 'collector', 'referrer']);
        $data = ['client' => $c];

        if ($this->tab === 'resumen') {
            $data['risk'] = app(RiskService::class)->evaluate($c);
            $data['referralUrl'] = app(ReferralService::class)->shareUrl($c);
            $data['referrals'] = $c->referrals()->with('referred:id,first_name,last_name')->get();
            $data['openCharges'] = $c->charges()->open()->orderBy('due_date')->get();
        }
        if ($this->tab === 'cuenta') {
            $data['charges'] = $c->charges()->latest('due_date')->latest('id')->limit(60)->get();
            $data['payments'] = $c->payments()->with('receivedBy:id,name', 'collector:id,name')->latest('paid_at')->limit(60)->get();
            $data['paidTotal'] = (float) $c->payments()->revenue()->sum('amount');
            $data['overdue'] = $c->overdueAmount();
            $data['links'] = $c->hasMany(\App\Models\PaymentLink::class)->latest()->limit(5)->get();
        }
        if ($this->tab === 'servicio') {
            $data['events'] = $c->serviceEvents()->with('user:id,name')->latest()->limit(50)->get();
            $data['plans'] = Plan::where('active', true)->orderBy('price')->get();
            $data['devices'] = $c->devices()->get();
        }
        if ($this->tab === 'tickets') {
            $data['tickets'] = $c->tickets()->with('technician:id,name')->latest()->get();
            $data['visits'] = $c->visits()->with('collector:id,name')->latest('visited_at')->limit(20)->get();
        }
        if ($this->tab === 'documentos') {
            $data['attachments'] = $c->attachments()->latest()->get();
            $data['receipts'] = $c->payments()->revenue()->latest('paid_at')->limit(12)->get();
        }
        if ($this->tab === 'mensajes') {
            $data['messages'] = $c->messages()->latest()->limit(50)->get();
        }
        if ($this->tab === 'bitacora') {
            $data['logs'] = AuditLog::query()->where('auditable_type', 'Client')->where('auditable_id', $c->id)->latest('created_at')->limit(100)->get();
        }

        return view('livewire.clients.show', $data)->title($c->full_name);
    }
}
