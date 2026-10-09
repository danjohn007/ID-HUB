<?php

namespace App\Services;

use App\Models\Repeater;
use App\Models\Sector;

/**
 * Validación de cobertura por geolocalización: repetidor más cercano, distancia,
 * si cae dentro del radio de cobertura o de algún sector (azimut / apertura / alcance)
 * y si el repetidor tiene capacidad disponible.
 */
class CoverageService
{
    public function check(float $lat, float $lng): array
    {
        $repeaters = Repeater::query()->withLoad()->whereNotNull('lat')->get();
        $best = null;
        foreach ($repeaters as $r) {
            $d = haversine_km($lat, $lng, (float) $r->lat, (float) $r->lng);
            if (! $best || $d < $best['distance']) {
                $best = ['repeater' => $r, 'distance' => round($d, 2)];
            }
        }
        $inSector = $this->inAnySector($lat, $lng);
        if (! $best) {
            return ['repeater' => null, 'distance' => null, 'has_coverage' => $inSector !== null, 'sector' => $inSector, 'capacity_ok' => false, 'message' => 'No hay repetidores registrados'];
        }
        $r = $best['repeater'];
        $inRadius = $best['distance'] <= (float) $r->coverage_radius_km;
        $capacityOk = $r->utilization() < 100;
        $has = ($inRadius || $inSector) && $r->status !== 'offline';

        return [
            'repeater' => $r,
            'distance' => $best['distance'],
            'has_coverage' => $has,
            'sector' => $inSector,
            'capacity_ok' => $capacityOk,
            'message' => $has
                ? ($capacityOk ? "Con cobertura desde {$r->code} a {$best['distance']} km" : "Con cobertura pero {$r->code} está saturado ({$r->utilization()}%)")
                : "Sin cobertura: el repetidor más cercano ({$r->code}) está a {$best['distance']} km",
        ];
    }

    public function inAnySector(float $lat, float $lng): ?Sector
    {
        foreach (Sector::query()->with('tower:id,lat,lng')->get() as $s) {
            if (! $s->tower?->lat) {
                continue;
            }
            $d = haversine_km((float) $s->tower->lat, (float) $s->tower->lng, $lat, $lng);
            if ($d > (float) $s->range_km) {
                continue;
            }
            $bearing = $this->bearing((float) $s->tower->lat, (float) $s->tower->lng, $lat, $lng);
            $diff = abs((($bearing - $s->azimuth + 540) % 360) - 180);
            if ($diff <= $s->beamwidth / 2) {
                return $s;
            }
        }

        return null;
    }

    public function bearing(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));

        return (int) ((rad2deg(atan2($y, $x)) + 360) % 360);
    }
}
