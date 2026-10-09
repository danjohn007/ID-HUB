<?php

namespace App\Support;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\Tower;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Construye los datos para <x-ui.map> (marcadores, sectores, círculos, calor) por capa.
 * Estados de color: online (operativo), warning (advertencia), offline (fallo), unknown (sin comunicación).
 */
class MapData
{
    protected static function link(string $route, $param, string $label = 'Ver detalle'): string
    {
        return Route::has($route) ? '<a href="'.e(route($route, $param)).'" class="font-semibold text-brand-600">'.$label.' →</a>' : '';
    }

    public static function clients(array $filters = []): array
    {
        return Client::query()
            ->whereNotNull('lat')->whereNotIn('service_status', ['cancelado'])
            ->when($filters['zone_id'] ?? null, fn ($q, $v) => $q->where('zone_id', $v))
            ->when($filters['repeater_id'] ?? null, fn ($q, $v) => $q->where('repeater_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('service_status', $v))
            ->with('plan:id,name')
            ->get(['id', 'code', 'first_name', 'last_name', 'lat', 'lng', 'service_status', 'online', 'plan_id', 'balance', 'signal_dbm'])
            ->map(fn ($c) => [
                'lat' => (float) $c->lat, 'lng' => (float) $c->lng, 'type' => 'client', 'layer' => 'clients',
                'status' => $c->mapStatus(),
                'popup' => '<b>'.e($c->full_name).'</b><br>#'.$c->code.' · '.e($c->plan?->name).'<br>'
                    .Catalog::label('client_status', $c->service_status).($c->balance > 0 ? ' · Saldo '.money($c->balance) : '')
                    .($c->signal_dbm ? '<br>Señal '.$c->signal_dbm.' dBm' : '').'<br>'.static::link('clients.show', $c->id),
            ])->all();
    }

    public static function repeaters(): array
    {
        return Repeater::query()->withLoad()->whereNotNull('lat')->get()->map(function ($r) {
            $util = $r->utilization();

            return [
                'lat' => (float) $r->lat, 'lng' => (float) $r->lng, 'type' => 'repeater', 'layer' => 'repeaters',
                'status' => $r->status === 'online' && $util >= $r->alert_threshold ? 'warning' : $r->status,
                'pulse' => $r->status === 'offline', 'size' => 32,
                'tooltip' => $r->status === 'offline' ? $r->code.' Fuera de servicio' : null, 'permanentTooltip' => $r->status === 'offline',
                'popup' => '<b>'.e($r->code.' · '.$r->name).'</b><br>'.$r->clientLoad().' / '.$r->capacity.' clientes ('.$util.'%)<br>Estado: '
                    .Catalog::label('device_status', $r->status).'<br>'.static::link('network.repeaters.show', $r->id),
            ];
        })->all();
    }

    public static function coverageCircles(): array
    {
        return Repeater::query()->whereNotNull('lat')->get()->map(fn ($r) => [
            'lat' => (float) $r->lat, 'lng' => (float) $r->lng, 'radius' => (float) $r->coverage_radius_km * 1000,
            'color' => $r->status === 'offline' ? '#E5484D' : '#1A7FF5', 'opacity' => 0.06, 'layer' => 'coverage',
            'popup' => 'Cobertura estimada '.$r->code.' ('.$r->coverage_radius_km.' km)',
        ])->all();
    }

    public static function towers(): array
    {
        return Tower::query()->whereNotNull('lat')->withCount('repeaters')->get()->map(fn ($t) => [
            'lat' => (float) $t->lat, 'lng' => (float) $t->lng, 'type' => 'tower', 'layer' => 'towers', 'color' => '#F7881F', 'size' => 30,
            'popup' => '<b>'.e($t->code.' · '.$t->name).'</b><br>Altura '.$t->height_m.' m · '.$t->repeaters_count.' repetidores',
        ])->all();
    }

    public static function sectors(): array
    {
        return Sector::query()->with('tower:id,lat,lng,code')->get()->filter(fn ($s) => $s->tower?->lat)->map(fn ($s) => [
            'lat' => (float) $s->tower->lat, 'lng' => (float) $s->tower->lng, 'azimuth' => $s->azimuth, 'beamwidth' => $s->beamwidth,
            'range_km' => (float) $s->range_km, 'color' => '#1A7FF5',
            'popup' => '<b>'.e($s->name).'</b><br>Azimut '.$s->azimuth.'° · '.$s->beamwidth.'° · '.$s->range_km.' km<br>'.e($s->frequency).' · '.$s->power_dbm.' dBm',
        ])->values()->all();
    }

    public static function staff(): array
    {
        return User::forTenant()->active()->role(['tecnico', 'cobrador'])->whereNotNull('last_lat')->get()->map(fn ($u) => [
            'lat' => (float) $u->last_lat, 'lng' => (float) $u->last_lng,
            'type' => $u->role === 'tecnico' ? 'technician' : 'collector', 'layer' => $u->role === 'tecnico' ? 'technicians' : 'collectors',
            'color' => $u->role === 'tecnico' ? '#1A7FF5' : '#6048D0', 'size' => 28,
            'popup' => '<b>'.e($u->name).'</b><br>'.$u->roleLabel().'<br>Ubicación '.($u->last_location_at?->diffForHumans() ?? ''),
        ])->all();
    }

    public static function tickets(): array
    {
        return Ticket::query()->open()->with(['client:id,lat,lng,first_name,last_name', 'repeater:id,lat,lng,code'])->get()
            ->map(function ($t) {
                $lat = $t->client?->lat ?? $t->repeater?->lat;
                $lng = $t->client?->lng ?? $t->repeater?->lng;
                if (! $lat) {
                    return null;
                }

                return [
                    'lat' => (float) $lat + 0.0002, 'lng' => (float) $lng + 0.0002, 'type' => 'ticket', 'layer' => 'tickets', 'size' => 24,
                    'color' => ['critica' => '#E5484D', 'alta' => '#F7881F', 'media' => '#1A7FF5', 'baja' => '#22A06B'][$t->priority] ?? '#1A7FF5',
                    'popup' => '<b>'.e($t->folio).'</b> · '.Catalog::label('ticket_priorities', $t->priority).'<br>'.e($t->subject).'<br>'.static::link('tickets.show', $t->id),
                ];
            })->filter()->values()->all();
    }

    public static function installations(): array
    {
        return InstallationOrder::query()->whereIn('status', ['aceptada', 'programada', 'en_proceso'])->with('client:id,lat,lng,first_name,last_name', 'prospect:id,lat,lng,name')->get()
            ->map(function ($o) {
                $lat = $o->client?->lat ?? $o->prospect?->lat;
                if (! $lat) {
                    return null;
                }

                return [
                    'lat' => (float) $lat, 'lng' => (float) ($o->client?->lng ?? $o->prospect?->lng), 'type' => 'installation', 'layer' => 'installations',
                    'color' => '#00A0A8', 'size' => 24,
                    'popup' => '<b>'.e($o->folio).'</b> · '.Catalog::label('installation_status', $o->status).'<br>'.e($o->customerName()).'<br>'.static::link('installations.show', $o->id),
                ];
            })->filter()->values()->all();
    }

    public static function prospects(): array
    {
        return Prospect::query()->whereNotNull('lat')->whereNotIn('status', ['convertido', 'descartado'])->get()->map(fn ($p) => [
            'lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'type' => 'prospect', 'layer' => 'prospects',
            'color' => $p->has_coverage ? '#6048D0' : '#94A3B8', 'size' => 22,
            'popup' => '<b>'.e($p->name).'</b><br>'.Catalog::label('prospect_status', $p->status).' · '.($p->has_coverage ? 'Con cobertura' : 'Sin cobertura')
                .'<br>'.($p->distance_km ? $p->distance_km.' km al repetidor más cercano' : ''),
        ])->all();
    }

    public static function demandHeat(): array
    {
        return Prospect::query()->whereNotNull('lat')->whereNotIn('status', ['convertido', 'descartado'])->get(['lat', 'lng'])
            ->map(fn ($p) => [(float) $p->lat, (float) $p->lng, 0.7])->all();
    }
}
