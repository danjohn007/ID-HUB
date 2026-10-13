<?php

namespace App\Services\Field;

use App\Models\Client;
use App\Models\FieldRoute;
use App\Models\InstallationOrder;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Zone;
use App\Services\TicketService;
use App\Support\Catalog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planeación de rutas de técnicos y cobradores (sección 25):
 * agrupa actividades (tickets, órdenes de instalación, clientes con adeudo) por zona,
 * usuario, fecha y prioridad; las ordena con RouteOptimizer y guarda FieldRoute + RouteStops.
 *
 * Cada actividad se representa como un "punto":
 *   ['key' => 'ticket:12', 'type' => 'ticket', 'id' => 12, 'lat', 'lng', 'rank', 'priority',
 *    'title', 'subtitle', 'address', 'zone_id', 'badge' => [label, color], 'phone']
 */
class FieldPlanner
{
    public const INSTALL_STATUSES = ['aceptada', 'programada', 'en_proceso'];

    public function __construct(protected RouteOptimizer $optimizer) {}

    /** Actividades candidatas para planear la ruta de un usuario. */
    public function candidates(?User $user, string $routeType, Carbon $date, array $filters = []): Collection
    {
        $zoneId = $filters['zone_id'] ?? null;
        $priority = $filters['priority'] ?? null;
        $includeUnassigned = $filters['unassigned'] ?? true;
        $points = collect();

        if ($routeType === 'cobranza') {
            $clients = Client::query()
                ->where('balance', '>', 0)
                ->whereIn('service_status', ['activo', 'moroso', 'suspendido', 'en_revision'])
                ->when($user, fn ($q) => $q->where(fn ($w) => $w->where('collector_id', $user->id)->when($includeUnassigned, fn ($x) => $x->orWhereNull('collector_id'))))
                ->when($zoneId, fn ($q) => $q->where('zone_id', $zoneId))
                ->orderByDesc('balance')->limit((int) ($filters['limit'] ?? 40))
                ->get(['id', 'code', 'first_name', 'last_name', 'address', 'colonia', 'lat', 'lng', 'zone_id', 'balance', 'service_status', 'phone', 'collector_id']);
            foreach ($clients as $c) {
                $points->push($this->clientPoint($c));
            }

            return $points;
        }

        if ($filters['tickets'] ?? true) {
            $tickets = Ticket::query()->open()
                ->with(['client:id,code,first_name,last_name,address,colonia,lat,lng,zone_id,phone', 'repeater:id,code,name,lat,lng,zone_id,location'])
                ->when($user, fn ($q) => $q->where(fn ($w) => $w->where('technician_id', $user->id)->when($includeUnassigned, fn ($x) => $x->orWhereNull('technician_id'))))
                ->when($priority, fn ($q) => $q->where('priority', $priority))
                ->get();
            foreach ($tickets as $t) {
                $p = $this->ticketPoint($t);
                if (! $zoneId || (int) $p['zone_id'] === (int) $zoneId) {
                    $points->push($p);
                }
            }
        }

        if ($filters['installations'] ?? true) {
            $orders = InstallationOrder::query()->whereIn('status', self::INSTALL_STATUSES)
                ->with(['client:id,code,first_name,last_name,address,colonia,lat,lng,zone_id,phone', 'prospect:id,name,address,lat,lng,zone_id,phone'])
                ->when($user, fn ($q) => $q->where(fn ($w) => $w->where('technician_id', $user->id)->when($includeUnassigned, fn ($x) => $x->orWhereNull('technician_id'))))
                ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhereDate('scheduled_at', $date->toDateString())->orWhere('status', 'en_proceso'))
                ->get();
            foreach ($orders as $o) {
                $p = $this->installationPoint($o);
                if (! $zoneId || (int) $p['zone_id'] === (int) $zoneId) {
                    $points->push($p);
                }
            }
        }

        return $points->values();
    }

    public function ticketPoint(Ticket $t): array
    {
        $c = $t->client;
        $lat = $c?->lat ?? $t->repeater?->lat;
        $lng = $c?->lng ?? $t->repeater?->lng;

        return [
            'key' => 'ticket:'.$t->id, 'type' => 'ticket', 'id' => $t->id,
            'lat' => $lat ? (float) $lat : null, 'lng' => $lng ? (float) $lng : null,
            'priority' => $t->priority, 'rank' => RouteOptimizer::PRIORITY_RANK[$t->priority] ?? 2,
            'title' => $c ? $c->full_name : ('Repetidor '.$t->repeater?->code),
            'subtitle' => $t->folio.' · '.$t->subject,
            'address' => $c ? trim($c->address.($c->colonia ? ', '.$c->colonia : '')) : $t->repeater?->location,
            'zone_id' => $c?->zone_id ?? $t->repeater?->zone_id,
            'phone' => $c?->phone,
            'assigned' => (bool) $t->technician_id,
            'badge' => [Catalog::label('ticket_priorities', $t->priority), Catalog::color('ticket_priorities', $t->priority)],
            'status' => [Catalog::label('ticket_status', $t->status), Catalog::color('ticket_status', $t->status)],
            'sla' => $t->slaState(),
            'due' => $t->sla_due_at?->toIso8601String(),
        ];
    }

    public function installationPoint(InstallationOrder $o): array
    {
        $loc = $o->client ?? $o->prospect;

        return [
            'key' => 'installation_order:'.$o->id, 'type' => 'installation_order', 'id' => $o->id,
            'lat' => $loc?->lat ? (float) $loc->lat : null, 'lng' => $loc?->lng ? (float) $loc->lng : null,
            'priority' => 'media', 'rank' => 2,
            'title' => $o->customerName(),
            'subtitle' => $o->folio.' · Instalación'.($o->scheduled_at ? ' '.$o->scheduled_at->format('H:i') : ''),
            'address' => $loc ? trim($loc->address.(($loc->colonia ?? null) ? ', '.$loc->colonia : '')) : null,
            'zone_id' => $loc?->zone_id,
            'phone' => $loc?->phone,
            'assigned' => (bool) $o->technician_id,
            'badge' => ['Instalación', 'teal'],
            'status' => [Catalog::label('installation_status', $o->status), Catalog::color('installation_status', $o->status)],
            'sla' => null, 'due' => $o->scheduled_at?->toIso8601String(),
        ];
    }

    public function clientPoint(Client $c): array
    {
        return [
            'key' => 'client:'.$c->id, 'type' => 'client', 'id' => $c->id,
            'lat' => $c->lat ? (float) $c->lat : null, 'lng' => $c->lng ? (float) $c->lng : null,
            'priority' => $c->balance > 1000 ? 'alta' : 'media', 'rank' => $c->balance > 1000 ? 1 : 2,
            'title' => $c->full_name, 'subtitle' => '#'.$c->code.' · Adeudo '.money($c->balance),
            'address' => trim($c->address.($c->colonia ? ', '.$c->colonia : '')),
            'zone_id' => $c->zone_id, 'phone' => $c->phone,
            'assigned' => (bool) $c->collector_id,
            'badge' => ['Cobranza', 'purple'],
            'status' => [Catalog::label('client_status', $c->service_status), Catalog::color('client_status', $c->service_status)],
            'sla' => null, 'due' => null,
        ];
    }

    /** Punto de una parada guardada (para mostrar/re-optimizar). */
    public function stopPoint(RouteStop $stop): ?array
    {
        $s = $stop->stoppable;
        if (! $s) {
            return null;
        }
        $p = match (true) {
            $s instanceof Ticket => $this->ticketPoint($s->loadMissing('client', 'repeater')),
            $s instanceof InstallationOrder => $this->installationPoint($s->loadMissing('client', 'prospect')),
            $s instanceof Client => $this->clientPoint($s),
            default => null,
        };
        if ($p) {
            $p['lat'] = $stop->lat ? (float) $stop->lat : $p['lat'];
            $p['lng'] = $stop->lng ? (float) $stop->lng : $p['lng'];
            $p['stop_id'] = $stop->id;
            $p['stop_status'] = $stop->status;
            $p['sequence'] = $stop->sequence;
            $p['arrived_at'] = $stop->arrived_at?->format('H:i');
            $p['departed_at'] = $stop->departed_at?->format('H:i');
        }

        return $p;
    }

    /** Punto de inicio: última ubicación del usuario, centro de la zona o null. */
    public function startFor(?User $user, ?int $zoneId = null): ?array
    {
        if ($user?->last_lat && $user?->last_lng) {
            return ['lat' => (float) $user->last_lat, 'lng' => (float) $user->last_lng, 'label' => 'Última ubicación de '.$user->name];
        }
        if ($zoneId && ($z = Zone::find($zoneId)) && $z->lat) {
            return ['lat' => (float) $z->lat, 'lng' => (float) $z->lng, 'label' => 'Centro de '.$z->name];
        }
        $center = tenant()?->setting('map.center');

        return is_array($center) && count($center) === 2 ? ['lat' => (float) $center[0], 'lng' => (float) $center[1], 'label' => 'Oficina'] : null;
    }

    public function suggest(array $points, ?array $start, bool $byPriority = true): array
    {
        return $this->optimizer->optimize($points, $start, $byPriority);
    }

    /**
     * Guarda la ruta del usuario para la fecha. Si ya existe, conserva las paradas atendidas
     * y reemplaza las pendientes por las nuevas (en el orden recibido).
     * Asigna al usuario los tickets/órdenes/clientes que no tenían responsable.
     */
    public function save(User $user, string $routeType, Carbon $date, array $orderedPoints, ?int $zoneId = null, ?array $start = null, ?float $distanceKm = null): FieldRoute
    {
        return DB::transaction(function () use ($user, $routeType, $date, $orderedPoints, $zoneId, $start, $distanceKm) {
            $route = FieldRoute::query()->where('user_id', $user->id)->where('type', $routeType)->whereDate('date', $date->toDateString())->first()
                ?? new FieldRoute(['user_id' => $user->id, 'type' => $routeType, 'date' => $date->toDateString(), 'status' => 'planned']);
            $route->fill([
                'zone_id' => $zoneId,
                'distance_km' => $distanceKm,
                'start_lat' => $start['lat'] ?? null,
                'start_lng' => $start['lng'] ?? null,
            ])->save();

            $kept = $route->stops()->whereIn('status', ['arrived', 'done'])->get();
            $route->stops()->whereNotIn('status', ['arrived', 'done'])->delete();
            $keptKeys = $kept->map(fn ($s) => $s->stoppable_type.':'.$s->stoppable_id)->all();
            $seq = (int) $kept->max('sequence');

            foreach ($orderedPoints as $p) {
                if (in_array($p['key'], $keptKeys, true)) {
                    continue;
                }
                $route->stops()->create([
                    'stoppable_type' => $p['type'], 'stoppable_id' => $p['id'], 'sequence' => ++$seq,
                    'lat' => $p['lat'], 'lng' => $p['lng'], 'status' => 'pending',
                    'distance_km' => $p['leg_km'] ?? null,
                ]);
                $this->claim($user, $p);
            }

            return $route->load('stops');
        });
    }

    /** Asigna al usuario la actividad si no tenía responsable. */
    protected function claim(User $user, array $p): void
    {
        if ($p['type'] === 'ticket' && ($t = Ticket::find($p['id'])) && ! $t->technician_id) {
            app(TicketService::class)->assign($t, $user);
        } elseif ($p['type'] === 'installation_order' && ($o = InstallationOrder::find($p['id'])) && ! $o->technician_id) {
            $o->update(['technician_id' => $user->id]);
        } elseif ($p['type'] === 'client' && ($c = Client::find($p['id'])) && ! $c->collector_id) {
            $c->update(['collector_id' => $user->id]);
        }
    }

    /** Ruta del usuario para una fecha (técnico por defecto). */
    public function routeFor(User $user, Carbon $date, string $routeType = 'tecnico'): ?FieldRoute
    {
        return FieldRoute::query()->where('user_id', $user->id)->where('type', $routeType)
            ->whereDate('date', $date->toDateString())
            ->with(['stops.stoppable'])->first();
    }

    /** Polilínea y marcadores numerados para <x-ui.map>. */
    public function mapData(array $ordered, ?array $start = null): array
    {
        $markers = [];
        $line = [];
        if ($start) {
            $markers[] = ['lat' => $start['lat'], 'lng' => $start['lng'], 'type' => 'technician', 'color' => '#0B1B3A', 'size' => 28, 'popup' => e($start['label'] ?? 'Inicio'), 'layer' => 'inicio'];
            $line[] = [$start['lat'], $start['lng']];
        }
        foreach ($ordered as $i => $p) {
            if (! $p['lat']) {
                continue;
            }
            // Atendida: gris con ✓ · en sitio: ámbar · omitida: gris claro · pendiente: color de prioridad
            $status = $p['stop_status'] ?? 'pending';
            $number = (string) ($p['sequence'] ?? $i + 1);
            $markers[] = [
                'lat' => $p['lat'], 'lng' => $p['lng'],
                'type' => $p['type'] === 'installation_order' ? 'installation' : ($p['type'] === 'client' ? 'client' : 'ticket'),
                'color' => match ($status) {
                    'done' => '#64748B',
                    'skipped' => '#CBD5E1',
                    'arrived' => '#F59E0B',
                    default => ['critica' => '#E5484D', 'alta' => '#F7881F', 'media' => '#1A7FF5', 'baja' => '#22A06B'][$p['priority']] ?? '#1A7FF5',
                },
                'pulse' => $status === 'arrived',
                'size' => 26, 'layer' => 'paradas',
                'tooltip' => match ($status) { 'done' => '✓ '.$number, 'skipped' => '– '.$number, default => $number },
                'permanentTooltip' => true,
                'popup' => '<b>'.$number.'. '.e($p['title']).'</b><br>'.e($p['subtitle']).'<br><span style="color:#64748b">'.e($p['address']).'</span>'
                    .'<br><b>'.e(['done' => 'Atendida', 'skipped' => 'Omitida', 'arrived' => 'En sitio'][$status] ?? 'Pendiente').'</b>',
            ];
            $line[] = [$p['lat'], $p['lng']];
        }

        return [
            'markers' => $markers,
            'lines' => count($line) > 1 ? [['points' => $line, 'color' => '#1A7FF5', 'weight' => 4, 'layer' => 'ruta']] : [],
            'fit' => true,
        ];
    }

    /** URL de Google Maps para una lista ordenada de puntos (máx. 10). */
    public function mapsUrl(array $ordered, ?array $start = null): ?string
    {
        $route = new FieldRoute;
        $route->setRelation('stops', collect($ordered)->map(fn ($p) => new RouteStop(['lat' => $p['lat'], 'lng' => $p['lng']])));
        $url = $route->mapsUrl();
        if ($url && $start) {
            $url .= '&origin='.$start['lat'].','.$start['lng'];
        }

        return $url;
    }
}
