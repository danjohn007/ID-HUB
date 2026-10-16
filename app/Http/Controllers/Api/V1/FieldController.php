<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\FieldRouteResource;
use App\Http\Resources\InstallationOrderResource;
use App\Http\Resources\RouteStopResource;
use App\Http\Resources\TicketResource;
use App\Models\FieldRoute;
use App\Models\InstallationOrder;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Services\Field\FieldTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App del técnico (sección 23 y 25): ruta del día, llegada / salida, en camino y ubicación GPS.
 */
class FieldController extends ApiController
{
    public function today(Request $request): JsonResponse
    {
        $user = $this->apiUser();
        $date = $request->query('date', now()->toDateString());
        $type = $request->query('type', $user->role === 'cobrador' ? 'cobranza' : 'tecnico');

        $route = FieldRoute::query()->where('user_id', $user->id)->where('type', $type)->whereDate('date', $date)
            ->with(['zone', 'stops.stoppable'])->first();
        $route?->stops->each(function (RouteStop $s) {
            if ($s->stoppable && method_exists($s->stoppable, 'client')) {
                $s->stoppable->loadMissing('client');
            }
        });

        $tickets = Ticket::query()->open()->where('technician_id', $user->id)->with('client')
            ->orderByRaw("FIELD(priority, 'critica', 'alta', 'media', 'baja')")->orderBy('sla_due_at')->limit(50)->get();
        $installations = $type === 'tecnico'
            ? InstallationOrder::query()->where('technician_id', $user->id)->whereIn('status', ['aceptada', 'programada', 'en_proceso'])
                ->with(['client', 'prospect', 'plan', 'repeater'])->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at')->limit(50)->get()
            : collect();
        $stopClients = $route?->stops->map(fn (RouteStop $s) => $s->stoppable instanceof \App\Models\Client ? $s->stoppable->id : $s->stoppable?->client_id)->filter();

        return $this->ok([
            'date' => $date,
            'type' => $type,
            'summary' => [
                'services' => $route ? $route->stops->count() : $tickets->count() + $installations->count(),
                'clients' => $route ? $stopClients->unique()->count() : $tickets->pluck('client_id')->merge($installations->pluck('client_id'))->filter()->unique()->count(),
                'tickets' => $tickets->count(),
                'installations' => $installations->count(),
                'done' => $route ? $route->stops->whereIn('status', ['done', 'skipped'])->count() : 0,
            ],
            'route' => $route ? new FieldRouteResource($route) : null,
            'open_tickets' => TicketResource::collection($tickets),
            'installations' => InstallationOrderResource::collection($installations),
            'message' => $route ? null : 'No tienes una ruta planeada para esta fecha.',
        ]);
    }

    public function arrive(Request $request, int $stopId): JsonResponse
    {
        $stop = $this->myStop($stopId);
        $gps = $this->gps($request);
        app(FieldTracker::class)->arrive($stop, $this->apiUser(), $gps['lat'], $gps['lng']);

        return $this->ok(['data' => new RouteStopResource($stop->fresh('stoppable'))], 'Llegada registrada.');
    }

    public function depart(Request $request, int $stopId): JsonResponse
    {
        $stop = $this->myStop($stopId);
        $gps = $this->gps($request);
        app(FieldTracker::class)->depart($stop, $this->apiUser(), $gps['lat'], $gps['lng']);

        return $this->ok(['data' => new RouteStopResource($stop->fresh('stoppable'))], 'Salida registrada.');
    }

    /** El técnico va en camino al ticket: notifica al cliente ("Técnico en camino"). */
    public function onWay(Request $request, int $ticketId): JsonResponse
    {
        $ticket = $this->myTicket($ticketId);
        $gps = $this->gps($request);
        app(FieldTracker::class)->onWay($ticket, $this->apiUser(), $gps['lat'], $gps['lng']);

        return $this->ok(['data' => new TicketResource($ticket->fresh('client'))], 'Se notificó al cliente que vas en camino.');
    }

    public function arriveTicket(Request $request, int $ticketId): JsonResponse
    {
        $ticket = $this->myTicket($ticketId);
        $gps = $this->gps($request);
        app(FieldTracker::class)->arriveTicket($ticket, $this->apiUser(), $gps['lat'], $gps['lng']);

        return $this->ok(['data' => new TicketResource($ticket->fresh('client'))], 'Llegada registrada.');
    }

    public function departTicket(Request $request, int $ticketId): JsonResponse
    {
        $ticket = $this->myTicket($ticketId);
        $gps = $this->gps($request);
        app(FieldTracker::class)->departTicket($ticket, $this->apiUser(), $gps['lat'], $gps['lng']);

        return $this->ok(['data' => new TicketResource($ticket->fresh('client'))], 'Salida registrada.');
    }

    public function location(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);
        $user = $this->apiUser();
        app(FieldTracker::class)->updateLocation($user, $data['lat'], $data['lng']);
        $user->refresh();

        return $this->ok([
            'location' => ['lat' => (float) $user->last_lat, 'lng' => (float) $user->last_lng],
            'updated_at' => $user->last_location_at?->toIso8601String(),
        ], 'Ubicación actualizada.');
    }

    /** Parada de una ruta del usuario autenticado dentro de su WISP. */
    protected function myStop(int $id): RouteStop
    {
        $stop = RouteStop::query()->with('route')->find($id);
        $route = $stop ? FieldRoute::query()->find($stop->field_route_id) : null; // aplica el scope de tenant
        if (! $stop || ! $route) {
            abort(404);
        }
        if ((int) $route->user_id !== (int) $this->apiUser()->id && ! $this->allows('routes.manage')) {
            $this->deny('Esta parada no pertenece a tu ruta.');
        }

        return $stop;
    }

    protected function myTicket(int $id): Ticket
    {
        $ticket = Ticket::query()->findOrFail($id);
        if ((int) $ticket->technician_id !== (int) $this->apiUser()->id && ! $this->allows('tickets.manage')) {
            $this->deny('Este ticket no está asignado a ti.');
        }

        return $ticket;
    }

    protected function gps(Request $request): array
    {
        $data = $this->check($request, [
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        return ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null];
    }
}
