<?php

namespace App\Livewire\Tickets;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Tickets\Concerns\ManagesTicketWork;
use App\Models\Outage;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Livewire\Component;

/**
 * Detalle del ticket: cliente, SLA, historial, comentarios, estado, asignación,
 * evidencias, materiales, firma y documento PDF de evidencia de servicio.
 */
class Show extends Component
{
    use ManagesTicketWork, Toasts;

    public Ticket $ticket;

    public bool $showStatus = false;

    public bool $showEdit = false;

    public string $assignTo = '';

    public array $edit = [];

    public function mount(Ticket $ticket): void
    {
        $this->ticket = $ticket;
        $this->assignTo = (string) ($ticket->technician_id ?? '');
        $this->newStatus = $ticket->status;
        $this->signedBy = (string) ($ticket->signed_by_name ?: $ticket->client?->full_name);
    }

    public function openStatus(?string $status = null): void
    {
        $this->newStatus = $status ?? $this->ticket->status;
        $this->statusNote = '';
        $this->showStatus = true;
    }

    protected function afterStatusChange(string $status): void
    {
        $this->showStatus = false;
    }

    public function assign(TicketService $tickets): void
    {
        $this->authorize('tickets.manage');
        $this->validate(['assignTo' => 'required'], [], ['assignTo' => 'técnico']);
        $tech = User::forTenant()->active()->findOrFail($this->assignTo);
        if ((int) $this->ticket->technician_id === $tech->id) {
            $this->toast('El ticket ya está asignado a '.$tech->name, 'info');

            return;
        }
        $tickets->assign($this->ticket, $tech);
        $this->ticket->refresh();
        $this->toast("Asignado a {$tech->name} y notificado al cliente");
    }

    public function openEdit(): void
    {
        $this->authorize('tickets.manage');
        $this->edit = $this->ticket->only(['subject', 'description', 'category', 'priority']);
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $this->authorize('tickets.manage');
        $this->validate([
            'edit.subject' => 'required|string|max:190',
            'edit.description' => 'nullable|string|max:3000',
            'edit.category' => 'required|in:'.implode(',', array_keys(config('wisp.ticket_categories'))),
            'edit.priority' => 'required|in:'.implode(',', array_keys(config('wisp.ticket_priorities'))),
        ], [], ['edit.subject' => 'asunto']);
        $priorityChanged = $this->edit['priority'] !== $this->ticket->priority;
        $this->ticket->fill($this->edit);
        if ($priorityChanged && $this->ticket->isOpen()) {
            $this->ticket->sla_due_at = $this->ticket->created_at->copy()->addMinutes(SlaPolicy::minutesFor($this->edit['priority']));
            $this->ticket->sla_warned = false;
            $this->ticket->sla_breached = $this->ticket->sla_due_at->isPast();
        }
        $this->ticket->save();
        if ($priorityChanged) {
            $this->ticket->addComment('Prioridad cambiada a '.config('wisp.ticket_priorities.'.$this->edit['priority'].'.0').'; SLA recalculado', 'system');
        }
        $this->showEdit = false;
        $this->toast('Ticket actualizado');
    }

    public function render()
    {
        $t = $this->ticket->load(['client.plan', 'client.zone', 'client.repeater', 'technician', 'repeater.tower', 'repeater.zone', 'outage', 'createdBy', 'installationOrder']);
        $outage = $t->outage ?? ($t->repeater_id ? Outage::query()->where('repeater_id', $t->repeater_id)->whereNull('ended_at')->latest('started_at')->first() : null);

        return view('livewire.tickets.show', $this->workData() + [
            't' => $t,
            'timeline' => $t->comments()->with('user:id,name,role')->get(),
            'technicians' => User::forTenant()->active()->role(['tecnico', 'supervisor'])->orderBy('name')->get(['id', 'name', 'role', 'on_call'])
                ->mapWithKeys(fn ($u) => [$u->id => $u->name.($u->on_call ? ' (de guardia)' : '').($u->role === 'supervisor' ? ' · Supervisor' : '')]),
            'workload' => Ticket::query()->open()->whereNotNull('technician_id')->selectRaw('technician_id, COUNT(*) n')->groupBy('technician_id')->pluck('n', 'technician_id'),
            'outage' => $outage,
            'related' => $t->client_id
                ? Ticket::query()->where('client_id', $t->client_id)->where('id', '!=', $t->id)->latest()->limit(5)->get(['id', 'folio', 'subject', 'status', 'created_at'])
                : ($t->repeater_id ? Ticket::query()->open()->where('repeater_id', $t->repeater_id)->where('id', '!=', $t->id)->latest()->limit(5)->get(['id', 'folio', 'subject', 'status', 'created_at']) : collect()),
            'affected' => $t->repeater && ! $t->client_id ? $t->repeater->clients()->whereIn('service_status', \App\Models\Client::BILLABLE)->count() : null,
        ])->title('Ticket '.$t->folio);
    }
}
