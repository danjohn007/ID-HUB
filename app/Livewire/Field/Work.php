<?php

namespace App\Livewire\Field;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Tickets\Concerns\ManagesTicketWork;
use App\Models\Ticket;
use Livewire\Component;

/**
 * App de campo del técnico — trabajo sobre un ticket (secciones 23 y 24): datos del cliente,
 * en camino / llegada / salida con GPS, cambio de estado, evidencias, materiales, comentarios y firma.
 * La lógica es la misma del detalle del ticket (ManagesTicketWork); esta vista está pensada para el celular.
 */
class Work extends Component
{
    use ManagesTicketWork, Toasts;

    public Ticket $ticket;

    public function mount(Ticket $ticket): void
    {
        $this->ticket = $ticket;
        abort_unless($this->canWork(), 403, 'Este ticket no está asignado a ti.');
        $this->newStatus = $ticket->status;
        $this->signedBy = (string) ($ticket->signed_by_name ?: $ticket->client?->full_name);
    }

    public function departHere($lat = null, $lng = null)
    {
        $this->ensureCanWork();
        app(\App\Services\Field\FieldTracker::class)->departTicket($this->ticket, auth()->user(), $lat, $lng);
        $this->toast('Salida registrada. Parada terminada.');

        return $this->redirectRoute('field.today', navigate: true);
    }

    public function render()
    {
        $t = $this->ticket->load(['client.plan', 'client.zone', 'client.repeater', 'repeater.zone', 'technician']);

        return view('livewire.field.work', $this->workData() + [
            't' => $t,
            'timeline' => $t->comments()->with('user:id,name')->limit(30)->get(),
            'nav' => $t->client?->lat
                ? 'https://www.google.com/maps/dir/?api=1&destination='.$t->client->lat.','.$t->client->lng
                : ($t->repeater?->lat ? 'https://www.google.com/maps/dir/?api=1&destination='.$t->repeater->lat.','.$t->repeater->lng : null),
        ])->title('Ticket '.$t->folio);
    }
}
