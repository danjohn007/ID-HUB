<?php

namespace App\Services;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Field\FieldTracker;
use App\Services\Messaging\MessageService;
use App\Support\FieldClock;

/**
 * Ciclo de vida de tickets: creación con SLA, asignación, cambios de estado e historial.
 */
class TicketService
{
    public function create(array $data): Ticket
    {
        $priority = $data['priority'] ?? 'media';
        $ticket = Ticket::create(array_merge([
            'folio' => next_folio('tickets', 'T'),
            'status' => 'abierto',
            'source' => 'admin',
            'created_by' => auth()->id(),
            'sla_due_at' => now()->addMinutes(SlaPolicy::minutesFor($priority, $data['category'] ?? null)),
        ], $data, ['priority' => $priority]));

        $ticket->addComment('Ticket creado ('.config("wisp.ticket_categories.{$ticket->category}", $ticket->category).')', 'system');

        if ($ticket->client && $ticket->source !== 'automatico') {
            app(MessageService::class)->notify($ticket->client, 'ticket_created', ['folio' => $ticket->folio]);
        }
        if (! empty($data['technician_id'])) {
            $ticket->update(['status' => 'asignado']);
        }
        app(AutomationEngine::class)->fire('ticket_created', $ticket);

        return $ticket;
    }

    public function assign(Ticket $ticket, User $technician, bool $notify = true): Ticket
    {
        $ticket->update([
            'technician_id' => $technician->id,
            'status' => $ticket->status === 'abierto' ? 'asignado' : $ticket->status,
        ]);
        $ticket->addComment("Asignado a {$technician->name}", 'assignment', false, ['technician_id' => $technician->id]);

        if ($notify && $ticket->client) {
            app(MessageService::class)->notify($ticket->client, 'ticket_assigned', ['folio' => $ticket->folio, 'tecnico' => $technician->name]);
        }

        return $ticket;
    }

    public function changeStatus(Ticket $ticket, string $status, ?string $note = null, bool $notify = true): Ticket
    {
        $old = $ticket->status;
        $at = FieldClock::now(); // hora real de la acción (app de campo sin señal)
        $changes = ['status' => $status];
        if ($status === 'en_proceso' && ! $ticket->arrived_at) {
            $changes['arrived_at'] = $at;
        }
        if ($status === 'resuelto') {
            $changes['resolved_at'] = $at;
            $changes['departed_at'] = $ticket->departed_at ?? $at;
            $changes['resolution'] = $note ?: $ticket->resolution;
            $changes['sla_breached'] = $ticket->sla_due_at && $at->greaterThan($ticket->sla_due_at);
        }
        if ($status === 'cerrado') {
            $changes['closed_at'] = $at;
            $changes['resolved_at'] = $ticket->resolved_at ?? $at;
        }
        $ticket->update($changes);
        if (in_array($status, ['resuelto', 'cerrado'], true)) {
            app(FieldTracker::class)->syncSubject($ticket, 'done'); // la parada de la ruta queda terminada
        }

        $label = config("wisp.ticket_status.$status.0", $status);
        $ticket->addComment('Estado: '.config("wisp.ticket_status.$old.0", $old).' → '.$label.($note ? " — {$note}" : ''), 'status', false, ['from' => $old, 'to' => $status]);

        if ($notify && $ticket->client && in_array($status, ['en_proceso', 'resuelto', 'cerrado'], true)) {
            app(MessageService::class)->notify($ticket->client, 'ticket_updated', ['folio' => $ticket->folio, 'descripcion' => $label]);
        }

        // Si se resuelve un ticket de caída, cerrar el outage asociado
        if (in_array($status, ['resuelto', 'cerrado'], true) && $ticket->outage && ! $ticket->outage->ended_at) {
            $ticket->outage->update(['ended_at' => $at]);
        }

        return $ticket;
    }
}
