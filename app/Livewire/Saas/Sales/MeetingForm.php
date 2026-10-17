<?php

namespace App\Livewire\Saas\Sales;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Sales\Concerns\SalesContext;
use App\Models\DemoMeeting;
use App\Models\DemoRequest;
use App\Services\DemoScheduler;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Recuadro para agendar o reprogramar una demo. Se abre desde cualquier pantalla de Ventas con
 *   $dispatch('schedule-demo', { leadId: 5 })   ·   $dispatch('schedule-demo', { meetingId: 9 })
 * y al guardar emite "meeting-saved" para que la pantalla se actualice.
 */
class MeetingForm extends Component
{
    use SalesContext, Toasts;

    public bool $show = false;

    public ?int $meetingId = null;

    /** Prospecto fijo (abierto desde su ficha o tablero): no se muestra el selector. */
    public bool $leadLocked = false;

    public array $form = [];

    #[On('schedule-demo')]
    public function open(?int $leadId = null, ?int $meetingId = null, ?string $date = null): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        $this->resetValidation();
        $this->meetingId = $meetingId;
        $meeting = $meetingId ? DemoMeeting::findOrFail($meetingId) : null;
        $lead = $meeting?->lead ?? ($leadId ? DemoRequest::findOrFail($leadId) : null);
        $start = $meeting?->starts_at ?? ($date ? Carbon::parse($date)->setTime(10, 0) : now()->addDay()->setTime(10, 0));

        $this->leadLocked = (bool) $lead;
        $this->form = [
            'lead_id' => (string) ($lead?->id ?? ''),
            'date' => $start->toDateString(),
            'time' => $start->format('H:i'),
            'duration' => (string) ($meeting?->duration_minutes ?? DemoScheduler::config('duration')),
            'assigned_to' => (string) ($meeting?->assigned_to ?? $lead?->assigned_to ?? auth()->id()),
            'meeting_url' => (string) ($meeting?->meeting_url ?? DemoScheduler::config('meeting_url')),
            'notes' => (string) ($meeting?->notes ?? ''),
            'notify' => true,
        ];
        $this->show = true;
    }

    public function save(DemoScheduler $scheduler): void
    {
        $data = $this->validate([
            'form.lead_id' => 'required|exists:demo_requests,id',
            'form.date' => 'required|date_format:Y-m-d',
            'form.time' => 'required|date_format:H:i',
            'form.duration' => 'required|integer|min:15|max:240',
            'form.assigned_to' => 'nullable|exists:users,id',
            'form.meeting_url' => 'nullable|url|max:255',
            'form.notes' => 'nullable|string|max:1000',
            'form.notify' => 'boolean',
        ], [], [
            'form.lead_id' => 'prospecto', 'form.date' => 'fecha', 'form.time' => 'hora', 'form.duration' => 'duración',
            'form.assigned_to' => 'responsable', 'form.meeting_url' => 'enlace', 'form.notes' => 'notas',
        ])['form'];

        $meeting = $this->meetingId ? DemoMeeting::findOrFail($this->meetingId) : null;
        $start = Carbon::parse("{$data['date']} {$data['time']}");
        if ($start->isPast() && (! $meeting || ! $meeting->starts_at->equalTo($start))) {
            $this->addError('form.time', 'Elige una fecha y hora futuras.');

            return;
        }
        if ($other = $scheduler->conflict($start, (int) $data['duration'], $meeting?->id)) {
            $this->addError('form.time', 'Choca con la demo de '.$other->lead->name.' a las '.$other->starts_at->format('H:i').'.');

            return;
        }

        $lead = DemoRequest::findOrFail($data['lead_id']);
        $moved = ! $meeting || ! $meeting->starts_at->equalTo($start); // solo entonces se avisa al prospecto
        $meeting = $scheduler->schedule($lead, $start, [
            'duration_minutes' => (int) $data['duration'],
            'assigned_to' => $data['assigned_to'] ?: null,
            'meeting_url' => $data['meeting_url'] ?: null,
            'notes' => $data['notes'] ?: null,
        ], (bool) $data['notify'], $meeting);

        $this->show = false;
        $this->dispatch('meeting-saved', meetingId: $meeting->id);
        $this->toast('Demo agendada para el '.$start->translatedFormat('l j \d\e F, H:i')
            .($data['notify'] && $lead->email && $moved ? '. Enviamos la confirmación al prospecto.' : ''));
    }

    public function render()
    {
        return view('livewire.saas.sales.meeting-form', [
            'leads' => $this->show && ! $this->leadLocked
                ? DemoRequest::whereNotIn('status', DemoRequest::CLOSED_STAGES)->orderBy('name')->get()
                    ->mapWithKeys(fn ($l) => [$l->id => $l->name.($l->company ? ' · '.$l->company : '')])->all()
                : [],
            'lead' => $this->leadLocked && ($this->form['lead_id'] ?? null) ? DemoRequest::find($this->form['lead_id']) : null,
            'users' => $this->show ? $this->salesUsers() : [],
        ]);
    }
}
