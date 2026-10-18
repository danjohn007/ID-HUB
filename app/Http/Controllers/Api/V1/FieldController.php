<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\FieldRouteResource;
use App\Http\Resources\RouteStopResource;
use App\Http\Resources\TicketResource;
use App\Models\FieldRoute;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Services\Field\FieldPlanner;
use App\Services\Field\FieldTracker;
use App\Services\Field\RouteOptimizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App del técnico (sección 23 y 25): ruta del día, llegada / salida, en camino y ubicación GPS.
 */
class FieldController extends ApiController
{
    /**
     * Jornada del día: ruta guardada o, si no hay, ruta sugerida con sus tickets e instalaciones
     * (ordenada por urgencia y cercanía con FieldPlanner, igual que «Mi jornada» en la web).
     * `stops` trae ambas con el mismo formato; las sugeridas no tienen `id` hasta iniciar la jornada.
     */
    public function today(Request $request): JsonResponse
    {
        $user = $this->apiUser();
        $date = Carbon::parse($request->query('date', now()->toDateString()));
        $type = $request->query('type', $user->role === 'cobrador' ? 'cobranza' : 'tecnico');

        return $this->ok($this->dayPayload($date, $type));
    }

    /** Guarda como ruta del día la sugerencia actual (botón «Iniciar» de la web). */
    public function startDay(Request $request): JsonResponse
    {
        $user = $this->apiUser();
        $type = $request->input('type', $user->role === 'cobrador' ? 'cobranza' : 'tecnico');
        $route = $this->ensureRoute($type);

        return $this->ok($this->dayPayload(today(), $type), 'Ruta del día lista: '.$route->stops->count().' paradas.');
    }

    public function skip(int $stopId): JsonResponse
    {
        $stop = $this->myStop($stopId);
        if ($stop->status !== 'pending') {
            $this->deny('Sólo puedes omitir paradas pendientes.', 422);
        }
        $stop->update(['status' => 'skipped']);

        return $this->ok(['data' => new RouteStopResource($stop->fresh('stoppable'))], 'Parada omitida.');
    }

    protected function dayPayload(Carbon $date, string $type): array
    {
        $user = $this->apiUser();
        $planner = app(FieldPlanner::class);
        $route = $planner->routeFor($user, $date, $type);
        $route?->loadMissing('zone');

        if ($route) {
            $stops = RouteStopResource::collection($route->stops)->resolve();
            $start = $route->start_lat ? ['lat' => (float) $route->start_lat, 'lng' => (float) $route->start_lng, 'label' => 'Inicio'] : $planner->startFor($user);
            $mapsUrl = $route->mapsUrl();
            // Igual que la web: si la ruta no guardó distancia, se calcula con sus paradas
            $distance = $route->distance_km ?? app(RouteOptimizer::class)->pathLength(
                $route->stops->map(fn ($s) => $planner->stopPoint($s))->filter()->values()->all(), $start
            );
        } else {
            $points = $planner->candidates($user, $type, $date, ['unassigned' => false])->all();
            $start = $planner->startFor($user);
            $ordered = $points ? $planner->suggest($points, $start, true)['order'] : [];
            $stops = collect($ordered)->values()->map(fn ($p, $i) => $this->suggestedStop($p, $i + 1))->all();
            $mapsUrl = $ordered ? $planner->mapsUrl($ordered, $start) : null;
            $distance = $ordered ? app(RouteOptimizer::class)->pathLength($ordered, $start) : 0;
        }

        $tickets = Ticket::query()->open()->where('technician_id', $user->id)->with('client')
            ->orderByRaw("CASE priority WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END")
            ->orderBy('sla_due_at')->limit(50)->get();
        $done = collect($stops)->whereIn('status', ['done', 'skipped'])->count();

        return [
            'date' => $date->toDateString(),
            'route' => $route ? new FieldRouteResource($route) : null,
            'suggested' => ! $route && $stops,
            'stops' => $stops,
            'summary' => [
                'stops' => count($stops),
                'done' => $done,
                'distance_km' => round((float) $distance, 1),
                'progress' => $stops ? (int) round($done / count($stops) * 100) : 0,
            ],
            'start' => $start,
            'maps_url' => $mapsUrl,
            'open_tickets' => TicketResource::collection($tickets),
            'message' => $stops ? null : 'No tienes tickets abiertos ni instalaciones programadas. Tu supervisor puede asignarte una ruta.',
        ];
    }

    /** Punto sugerido con el mismo formato que RouteStopResource (sin id: aún no es parada guardada). */
    protected function suggestedStop(array $p, int $sequence): array
    {
        return [
            'id' => null,
            'key' => $p['key'],
            'ref_id' => $p['id'],
            'sequence' => $sequence,
            'status' => 'pending',
            'status_label' => ['value' => 'pending', 'label' => config('wisp.route_stop_status.pending.0'), 'color' => config('wisp.route_stop_status.pending.1')],
            'type' => $p['type'],
            'title' => $p['title'],
            'subtitle' => $p['subtitle'],
            'address' => $p['address'],
            'phone' => $p['phone'],
            'priority' => $p['priority'],
            'badge' => ['label' => $p['badge'][0], 'color' => $p['badge'][1]],
            'sla' => $p['sla'],
            'subject' => ['id' => $p['id']],
            'client' => null,
            'location' => $p['lat'] && $p['lng'] ? ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']] : null,
            'navigation_url' => $p['lat'] && $p['lng'] ? 'https://www.google.com/maps/dir/?api=1&destination='.$p['lat'].','.$p['lng'] : null,
            'arrived_at' => null,
            'departed_at' => null,
        ];
    }

    /** Ruta del día: la guardada o la sugerida, que se guarda en ese momento. */
    protected function ensureRoute(string $type): FieldRoute
    {
        $user = $this->apiUser();
        $planner = app(FieldPlanner::class);
        if ($route = $planner->routeFor($user, today(), $type)) {
            return $route;
        }
        $start = $planner->startFor($user);
        $points = $planner->candidates($user, $type, today(), ['unassigned' => false])->all();
        if (! $points) {
            $this->deny('No tienes actividades para armar la ruta de hoy.', 422);
        }
        $result = $planner->suggest($points, $start, true);

        return $planner->save($user, $type, today(), $result['order'], null, $result['start'], $result['distance_km']);
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
