<?php

namespace App\Services\Field;

use App\Models\FieldRoute;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Messaging\MessageService;
use App\Services\TicketService;
use App\Support\FieldClock;

/**
 * Registro de actividad en campo: en camino, llegada y salida (sección 23),
 * con la ubicación GPS del técnico. Las horas son las de la acción (FieldClock): lo hecho sin señal
 * y enviado después conserva la hora en que ocurrió.
 */
class FieldTracker
{
    public function updateLocation(User $user, $lat, $lng): void
    {
        $at = FieldClock::now();
        // Una acción vieja de la bandeja sin conexión no reemplaza una ubicación más reciente.
        if ($this->valid($lat, $lng) && (! $user->last_location_at || $at->gte($user->last_location_at))) {
            $user->forceFill(['last_lat' => $lat, 'last_lng' => $lng, 'last_location_at' => $at])->saveQuietly();
        }
    }

    /** El técnico sale hacia el domicilio: se notifica al cliente ("Técnico en camino"). */
    public function onWay(Ticket $ticket, User $user, $lat = null, $lng = null): void
    {
        $this->updateLocation($user, $lat, $lng);
        $ticket->forceFill(['on_way_at' => FieldClock::now()])->save();
        $ticket->addComment("{$user->name} va en camino al domicilio", 'system', false, $this->geoMeta($lat, $lng));
        if ($ticket->client) {
            app(MessageService::class)->notify($ticket->client, 'technician_on_way', ['tecnico' => $user->name, 'folio' => $ticket->folio]);
        }
    }

    public function arrive(RouteStop $stop, User $user, $lat = null, $lng = null): void
    {
        $this->updateLocation($user, $lat, $lng);
        $stop->update(['status' => 'arrived', 'arrived_at' => FieldClock::now()]);
        $this->startRoute($stop->route);

        if ($stop->stoppable instanceof Ticket) {
            $this->arriveTicket($stop->stoppable, $user, $lat, $lng);
        }
    }

    public function depart(RouteStop $stop, User $user, $lat = null, $lng = null): void
    {
        $this->updateLocation($user, $lat, $lng);
        $stop->update(['status' => 'done', 'departed_at' => FieldClock::now(), 'arrived_at' => $stop->arrived_at ?? FieldClock::now()]);

        if ($stop->stoppable instanceof Ticket) {
            $this->departTicket($stop->stoppable, $user, $lat, $lng);
        }

        $route = $stop->route;
        if ($route && ! $route->stops()->whereIn('status', ['pending', 'arrived'])->exists()) {
            $route->update(['status' => 'completed']);
        }
    }

    /** Llegada registrada directamente sobre el ticket (pantalla de trabajo). */
    public function arriveTicket(Ticket $ticket, User $user, $lat = null, $lng = null): void
    {
        $this->updateLocation($user, $lat, $lng);
        if (! $ticket->arrived_at || $ticket->departed_at) {
            $ticket->forceFill(['arrived_at' => FieldClock::now(), 'departed_at' => null])->save();
        }
        $ticket->addComment("{$user->name} llegó al sitio", 'system', false, $this->geoMeta($lat, $lng));
        if (in_array($ticket->status, ['abierto', 'asignado', 'en_espera'], true)) {
            app(TicketService::class)->changeStatus($ticket, 'en_proceso', 'Técnico en sitio');
        }
        $this->syncStop($ticket, $user, 'arrived');
    }

    public function departTicket(Ticket $ticket, User $user, $lat = null, $lng = null): void
    {
        $this->updateLocation($user, $lat, $lng);
        $ticket->forceFill(['departed_at' => FieldClock::now()])->save();
        $ticket->addComment("{$user->name} salió del sitio", 'system', false, $this->geoMeta($lat, $lng));
        $this->syncStop($ticket, $user, 'done');
    }

    /** Refleja la llegada/salida del ticket en la parada de la ruta de hoy (si existe). */
    protected function syncStop(Ticket $ticket, User $user, string $status): void
    {
        $stop = RouteStop::query()->where('stoppable_type', 'ticket')->where('stoppable_id', $ticket->id)
            ->whereHas('route', fn ($q) => $q->where('user_id', $user->id)->whereDate('date', FieldClock::now()->toDateString()))
            ->first();
        if (! $stop || $stop->status === $status) {
            return;
        }
        $changes = ['status' => $status];
        if ($status === 'arrived' && ! $stop->arrived_at) {
            $changes['arrived_at'] = FieldClock::now();
        }
        if ($status === 'done') {
            $changes['departed_at'] = FieldClock::now();
            $changes['arrived_at'] = $stop->arrived_at ?? FieldClock::now();
        }
        $stop->update($changes);
        $this->startRoute($stop->route);
    }

    protected function startRoute(?FieldRoute $route): void
    {
        if ($route && $route->status === 'planned') {
            $route->update(['status' => 'in_progress']);
        }
    }

    protected function geoMeta($lat, $lng): array
    {
        return $this->valid($lat, $lng) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : [];
    }

    protected function valid($lat, $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && (float) $lat != 0.0;
    }
}
