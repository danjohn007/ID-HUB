<?php

namespace App\Livewire\Field;

use App\Livewire\Concerns\Toasts;
use App\Models\FieldRoute;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Services\Field\FieldPlanner;
use App\Services\Field\FieldTracker;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * App de campo del técnico — «Mi jornada»: paradas del día (ruta guardada o sugerida
 * a partir de sus tickets e instalaciones), navegación, en camino, llegada y salida con GPS.
 */
#[Title('Mi jornada')]
class Today extends Component
{
    use Toasts;

    protected function route(FieldPlanner $planner): ?FieldRoute
    {
        return $planner->routeFor(auth()->user(), today(), 'tecnico');
    }

    /** Paradas: de la ruta guardada, o sugeridas si no hay ruta. */
    protected function stops(FieldPlanner $planner, ?FieldRoute $route): array
    {
        if ($route) {
            return $route->stops->map(fn ($s) => $planner->stopPoint($s))->filter()->values()->all();
        }
        $points = $planner->candidates(auth()->user(), 'tecnico', today(), ['unassigned' => false])->all();
        if (! $points) {
            return [];
        }

        return $planner->suggest($points, $planner->startFor(auth()->user()), true)['order'];
    }

    /** Guarda como ruta del día la sugerencia actual. */
    protected function ensureRoute(FieldPlanner $planner): FieldRoute
    {
        if ($route = $this->route($planner)) {
            return $route;
        }
        $start = $planner->startFor(auth()->user());
        $points = $planner->candidates(auth()->user(), 'tecnico', today(), ['unassigned' => false])->all();
        $result = $planner->suggest($points, $start, true);

        return $planner->save(auth()->user(), 'tecnico', today(), $result['order'], null, $result['start'], $result['distance_km']);
    }

    public function startDay(FieldPlanner $planner): void
    {
        $route = $this->ensureRoute($planner);
        $this->toast('Ruta del día lista: '.$route->stops->count().' paradas');
    }

    protected function findStop(string $key, FieldPlanner $planner): RouteStop
    {
        [$type, $id] = explode(':', $key) + [null, null];
        $route = $this->ensureRoute($planner);
        $stop = $route->stops()->where('stoppable_type', $type)->where('stoppable_id', (int) $id)->first();
        abort_unless($stop, 404);

        return $stop->load('stoppable', 'route');
    }

    public function onWay(string $key, $lat = null, $lng = null): void
    {
        [$type, $id] = explode(':', $key) + [null, null];
        if ($type !== 'ticket') {
            app(FieldTracker::class)->updateLocation(auth()->user(), $lat, $lng);
            $this->toast('Ubicación actualizada');

            return;
        }
        $ticket = Ticket::query()->where('technician_id', auth()->id())->findOrFail((int) $id);
        app(FieldTracker::class)->onWay($ticket, auth()->user(), $lat, $lng);
        $this->toast('Se avisó al cliente que vas en camino');
    }

    public function arrive(string $key, $lat = null, $lng = null): void
    {
        $stop = $this->findStop($key, app(FieldPlanner::class));
        app(FieldTracker::class)->arrive($stop, auth()->user(), $lat, $lng);
        $this->toast('Llegada registrada'.(is_numeric($lat) ? ' con GPS' : ' (sin GPS)'));
    }

    public function depart(string $key, $lat = null, $lng = null): void
    {
        $stop = $this->findStop($key, app(FieldPlanner::class));
        app(FieldTracker::class)->depart($stop, auth()->user(), $lat, $lng);
        $this->toast('Salida registrada');
    }

    public function skip(string $key): void
    {
        $stop = $this->findStop($key, app(FieldPlanner::class));
        $stop->update(['status' => 'skipped']);
        $this->toast('Parada omitida', 'warning');
    }

    public function shareLocation($lat = null, $lng = null): void
    {
        app(FieldTracker::class)->updateLocation(auth()->user(), $lat, $lng);
        $this->toast(is_numeric($lat) ? 'Ubicación compartida con la oficina' : 'No se pudo obtener el GPS', is_numeric($lat) ? 'success' : 'warning');
    }

    public function render(FieldPlanner $planner)
    {
        $route = $this->route($planner);
        $stops = $this->stops($planner, $route);
        $done = collect($stops)->whereIn('stop_status', ['done', 'skipped'])->count();
        $next = collect($stops)->first(fn ($s) => ! in_array($s['stop_status'] ?? 'pending', ['done', 'skipped'], true));
        $start = $route?->start_lat ? ['lat' => (float) $route->start_lat, 'lng' => (float) $route->start_lng, 'label' => 'Inicio'] : $planner->startFor(auth()->user());

        return view('livewire.field.today', [
            'route' => $route,
            'stops' => $stops,
            'done' => $done,
            'next' => $next,
            'map' => $planner->mapData($stops, $start),
            'mapsUrl' => $route ? $route->mapsUrl() : ($stops ? $planner->mapsUrl($stops, $start) : null),
            'distance' => $route?->distance_km ?? ($stops ? app(\App\Services\Field\RouteOptimizer::class)->pathLength($stops, $start) : 0),
            'user' => auth()->user(),
        ]);
    }
}
