<?php

namespace App\Livewire\Routes;

use App\Livewire\Concerns\Toasts;
use App\Models\FieldRoute;
use App\Models\User;
use App\Models\Zone;
use App\Services\Field\FieldPlanner;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planeación de rutas de técnicos y cobradores (sección 25): agrupa actividades por zona,
 * usuario, fecha y prioridad, sugiere el orden óptimo y guarda la ruta del día.
 */
#[Title('Rutas')]
class Index extends Component
{
    use Toasts;

    #[Url(as: 'fecha')]
    public string $date = '';

    #[Url(as: 'tipo')]
    public string $type = 'tecnico';

    #[Url(as: 'usuario')]
    public string $userId = '';

    #[Url(as: 'zona')]
    public string $zoneId = '';

    public string $priority = '';

    public bool $byPriority = true;

    public bool $includeTickets = true;

    public bool $includeInstallations = true;

    public bool $includeUnassigned = true;

    /** Claves de actividades excluidas manualmente del plan */
    public array $excluded = [];

    /** Ruta sugerida (puntos ordenados) */
    public ?array $plan = null;

    public ?array $planStart = null;

    public ?float $planDistance = null;

    public ?int $previewRouteId = null;

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
        $this->type = in_array($this->type, ['tecnico', 'cobranza'], true) ? $this->type : 'tecnico';
    }

    public function updated($property): void
    {
        if (in_array($property, ['date', 'type', 'userId', 'zoneId', 'priority', 'includeTickets', 'includeInstallations', 'includeUnassigned'], true)) {
            if ($property === 'type') {
                $this->userId = '';
            }
            $this->resetPlan();
            $this->excluded = [];
            $this->refreshMap();
        }
    }

    protected function resetPlan(): void
    {
        $this->plan = null;
        $this->planStart = null;
        $this->planDistance = null;
        $this->previewRouteId = null;
    }

    protected function user(): ?User
    {
        return $this->userId ? User::forTenant()->active()->find($this->userId) : null;
    }

    protected function candidates(FieldPlanner $planner): array
    {
        return $planner->candidates($this->user(), $this->type, Carbon::parse($this->date), [
            'zone_id' => $this->zoneId ?: null,
            'priority' => $this->priority ?: null,
            'tickets' => $this->includeTickets,
            'installations' => $this->includeInstallations,
            'unassigned' => $this->includeUnassigned,
        ])->sortBy([['rank', 'asc'], ['title', 'asc']])->values()->all();
    }

    public function toggle(string $key): void
    {
        $this->excluded = in_array($key, $this->excluded, true)
            ? array_values(array_diff($this->excluded, [$key]))
            : [...$this->excluded, $key];
        $this->resetPlan();
    }

    public function selectAll(bool $all = true, ?FieldPlanner $planner = null): void
    {
        $this->excluded = $all ? [] : array_column($this->candidates($planner ?? app(FieldPlanner::class)), 'key');
        $this->resetPlan();
    }

    public function suggest(FieldPlanner $planner): void
    {
        $points = array_values(array_filter($this->candidates($planner), fn ($p) => ! in_array($p['key'], $this->excluded, true)));
        if (! $points) {
            $this->toast('Selecciona al menos una actividad', 'warning');

            return;
        }
        $start = $planner->startFor($this->user(), $this->zoneId ? (int) $this->zoneId : null);
        $result = $planner->suggest($points, $start, $this->byPriority);
        $this->plan = $result['order'];
        $this->planStart = $result['start'] ? $result['start'] + ['label' => $start['label'] ?? 'Inicio'] : null;
        $this->planDistance = $result['distance_km'];
        $this->previewRouteId = null;
        $this->refreshMap();
        $this->toast('Ruta sugerida: '.count($this->plan).' paradas · '.number_format($this->planDistance, 1).' km'.($result['unlocated'] ? ' ('.$result['unlocated'].' sin coordenadas al final)' : ''));
    }

    public function move(int $index, int $dir, FieldPlanner $planner): void
    {
        if (! $this->plan || ! isset($this->plan[$index], $this->plan[$index + $dir])) {
            return;
        }
        [$this->plan[$index], $this->plan[$index + $dir]] = [$this->plan[$index + $dir], $this->plan[$index]];
        $this->recomputeLegs($planner);
        $this->refreshMap();
    }

    public function removeFromPlan(int $index, FieldPlanner $planner): void
    {
        if (! isset($this->plan[$index])) {
            return;
        }
        $this->excluded[] = $this->plan[$index]['key'];
        array_splice($this->plan, $index, 1);
        $this->recomputeLegs($planner);
        $this->refreshMap();
    }

    protected function recomputeLegs(FieldPlanner $planner): void
    {
        $prev = $this->planStart;
        $total = 0.0;
        foreach ($this->plan as $i => $p) {
            $leg = $prev && $p['lat'] && $prev['lat'] ? haversine_km((float) $prev['lat'], (float) $prev['lng'], (float) $p['lat'], (float) $p['lng']) : null;
            $this->plan[$i]['leg_km'] = $leg !== null ? round($leg, 2) : null;
            $this->plan[$i]['sequence'] = $i + 1;
            $total += (float) $leg;
            if ($p['lat']) {
                $prev = $p;
            }
        }
        $this->planDistance = round($total, 2);
    }

    public function save(FieldPlanner $planner): void
    {
        $this->authorize('routes.manage');
        $user = $this->user();
        if (! $user) {
            $this->addError('userId', 'Selecciona el '.($this->type === 'tecnico' ? 'técnico' : 'cobrador').' de la ruta.');
            $this->toast('Selecciona a quién asignar la ruta', 'warning');

            return;
        }
        if (! $this->plan) {
            $this->toast('Genera primero la ruta sugerida', 'warning');

            return;
        }
        $route = $planner->save($user, $this->type, Carbon::parse($this->date), $this->plan, $this->zoneId ? (int) $this->zoneId : null, $this->planStart, $this->planDistance);
        $this->resetPlan();
        $this->previewRouteId = $route->id;
        $this->refreshMap();
        $this->toast("Ruta guardada para {$user->name} ({$route->stops->count()} paradas). Ya aparece en su app de campo.");
    }

    public function preview(int $id): void
    {
        $this->resetPlan();
        $this->previewRouteId = $id;
        $this->refreshMap();
    }

    public function deleteRoute(int $id): void
    {
        $this->authorize('routes.manage');
        $route = FieldRoute::findOrFail($id);
        $route->stops()->delete();
        $route->delete();
        if ($this->previewRouteId === $id) {
            $this->previewRouteId = null;
        }
        $this->refreshMap();
        $this->toast('Ruta eliminada', 'warning');
    }

    /** Datos del mapa según el estado actual (ruta sugerida, ruta guardada o candidatos). */
    protected function mapPayload(FieldPlanner $planner): array
    {
        if ($this->plan) {
            return $planner->mapData($this->plan, $this->planStart);
        }
        if ($this->previewRouteId && ($route = FieldRoute::with('stops.stoppable', 'user')->find($this->previewRouteId))) {
            $points = $route->stops->map(fn ($s) => $planner->stopPoint($s))->filter()->values()->all();
            $start = $route->start_lat ? ['lat' => (float) $route->start_lat, 'lng' => (float) $route->start_lng, 'label' => 'Inicio · '.$route->user?->name] : null;

            return $planner->mapData($points, $start);
        }
        $points = array_values(array_filter($this->candidates($planner), fn ($p) => ! in_array($p['key'], $this->excluded, true)));
        foreach ($points as $i => $p) {
            $points[$i]['sequence'] = '•';
        }
        $data = $planner->mapData($points, $planner->startFor($this->user(), $this->zoneId ? (int) $this->zoneId : null));
        $data['lines'] = [];
        foreach ($data['markers'] as $i => $m) {
            unset($data['markers'][$i]['tooltip'], $data['markers'][$i]['permanentTooltip']);
        }
        $data['markers'] = array_values($data['markers']);

        return $data;
    }

    protected function refreshMap(): void
    {
        $this->dispatch('map-data-route-map', data: $this->mapPayload(app(FieldPlanner::class)));
    }

    public function render(FieldPlanner $planner)
    {
        $candidates = $this->candidates($planner);
        $user = $this->user();
        $routes = FieldRoute::query()->whereDate('date', $this->date)->with(['user:id,name,role', 'zone:id,name', 'stops'])->orderBy('type')->get();
        $preview = $this->previewRouteId ? $routes->firstWhere('id', $this->previewRouteId) : null;

        return view('livewire.routes.index', [
            'candidates' => $candidates,
            'users' => User::forTenant()->active()->role($this->type === 'tecnico' ? 'tecnico' : 'cobrador')->orderBy('name')->get(['id', 'name', 'last_lat', 'last_location_at']),
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'selectedUser' => $user,
            'routes' => $routes,
            'preview' => $preview,
            'previewPoints' => $preview ? $preview->stops->load('stoppable')->map(fn ($s) => $planner->stopPoint($s))->filter()->values()->all() : [],
            'mapsUrl' => $this->plan ? $planner->mapsUrl($this->plan, $this->planStart) : $preview?->mapsUrl(),
            'map' => $this->mapPayload($planner),
            'includedCount' => count(array_filter($candidates, fn ($p) => ! in_array($p['key'], $this->excluded, true))),
        ]);
    }
}
