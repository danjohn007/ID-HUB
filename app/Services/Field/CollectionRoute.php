<?php

namespace App\Services\Field;

use App\Models\Charge;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\FieldRoute;
use App\Models\Payment;
use App\Models\RouteStop;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Jornada del cobrador (sección 26), compartida por «Mi cobranza» (web) y la API móvil:
 * clientes por visitar en orden de ruta y cercanía, visitados hoy, resumen del día y
 * cierre de la parada de la ruta al registrar un pago o una visita.
 */
class CollectionRoute
{
    /** Ruta de cobranza de hoy del cobrador. */
    public function todayRoute(int $collectorId): ?FieldRoute
    {
        return FieldRoute::query()->where('user_id', $collectorId)->where('type', 'cobranza')
            ->whereDate('date', now()->toDateString())->with('stops')->latest('id')->first();
    }

    /** Visitas de hoy del cobrador (más reciente primero). */
    public function visitsToday(int $collectorId): Collection
    {
        return CollectionVisit::query()->where('collector_id', $collectorId)->where('visited_at', '>=', now()->startOfDay())
            ->with('payment:id,folio,amount')->orderByDesc('visited_at')->get();
    }

    /**
     * Clientes del cobrador. $view: 'pendientes' (con saldo, sin visita hoy, en orden de ruta) | 'visitados'.
     * $cursor: [lat, lng] de la posición actual para ordenar por cercanía.
     */
    public function clients(User $collector, string $view = 'pendientes', ?string $search = null, ?array $cursor = null, ?Collection $visitsToday = null): Collection
    {
        $visitsToday ??= $this->visitsToday($collector->id);
        $visitedIds = $visitsToday->pluck('client_id')->unique();

        $base = Client::query()->where('collector_id', $collector->id)->whereIn('service_status', Client::BILLABLE)
            ->with(['zone:id,name', 'plan:id,name,price'])
            ->addSelect(['clients.*', 'oldest_due' => Charge::query()->selectRaw('MIN(due_date)')->whereColumn('charges.client_id', 'clients.id')->whereIn('charges.status', ['pending', 'partial'])])
            ->search($search);

        if ($view === 'visitados') {
            return (clone $base)->whereIn('clients.id', $visitedIds)->get()
                ->sortBy(fn ($c) => $visitedIds->search($c->id))->values();
        }

        $pending = (clone $base)->where('balance', '>', 0)->whereNotIn('clients.id', $visitedIds)->get();

        return $this->order($pending, $this->todayRoute($collector->id), $cursor, $collector);
    }

    /** Ordena: paradas de la ruta del día y después vecino más cercano desde la última posición. */
    public function order(Collection $clients, ?FieldRoute $route, ?array $cursor, User $collector): Collection
    {
        $ordered = collect();
        $remaining = $clients->keyBy('id');

        if ($route) {
            foreach ($route->stops as $stop) {
                if ($stop->stoppable_type === 'client' && $remaining->has($stop->stoppable_id)) {
                    $c = $remaining->pull($stop->stoppable_id);
                    $c->route_seq = $stop->sequence;
                    $ordered->push($c);
                    if ($c->lat) {
                        $cursor = [(float) $c->lat, (float) $c->lng];
                    }
                }
            }
        }

        if (! $cursor) {
            $cursor = $collector->last_lat ? [(float) $collector->last_lat, (float) $collector->last_lng] : null;
        }
        $withCoords = $remaining->filter(fn ($c) => $c->lat && $c->lng);
        $noCoords = $remaining->reject(fn ($c) => $c->lat && $c->lng);
        if (! $cursor && $withCoords->isNotEmpty()) {
            $first = $withCoords->sortByDesc('balance')->first();
            $cursor = [(float) $first->lat, (float) $first->lng];
        }
        while ($withCoords->isNotEmpty()) {
            $best = null;
            $bestD = INF;
            foreach ($withCoords as $c) {
                $d = haversine_km($cursor[0], $cursor[1], (float) $c->lat, (float) $c->lng);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $c;
                }
            }
            $best->distance_km = round($bestD, 2);
            $ordered->push($best);
            $withCoords->forget($best->id);
            $cursor = [(float) $best->lat, (float) $best->lng];
        }

        return $ordered->concat($noCoords->sortByDesc('balance')->values())->values();
    }

    /** Resumen del día del cobrador (tarjetas de «Mi cobranza»). */
    public function summary(User $collector, ?Collection $visitsToday = null): array
    {
        $visitsToday ??= $this->visitsToday($collector->id);
        $visitedIds = $visitsToday->pluck('client_id')->unique();
        $myPaymentsToday = Payment::query()->revenue()->where('collector_id', $collector->id)->where('paid_at', '>=', now()->startOfDay());
        $mine = Client::query()->where('collector_id', $collector->id)->whereIn('service_status', Client::BILLABLE)->where('balance', '>', 0);

        return [
            'collected' => (float) (clone $myPaymentsToday)->sum('amount'),
            'payments' => (clone $myPaymentsToday)->count(),
            'visits' => $visitsToday->count(),
            'pending' => (clone $mine)->whereNotIn('id', $visitedIds)->count(),
            'pending_amount' => (float) (clone $mine)->sum('balance'),
        ];
    }

    /** Marca como atendida la parada del cliente en la ruta de hoy (pago o visita registrada). */
    public function completeStop(int $collectorId, int $clientId): void
    {
        $route = $this->todayRoute($collectorId);
        if ($route) {
            $stops = fn () => RouteStop::where('field_route_id', $route->id)->where('stoppable_type', 'client')->where('stoppable_id', $clientId);
            $stops()->whereNull('arrived_at')->update(['arrived_at' => now()]);
            $stops()->update(['status' => 'done', 'departed_at' => now()]);
            if ($route->status === 'planned') {
                $route->update(['status' => 'in_progress']);
            }
        }
    }
}
