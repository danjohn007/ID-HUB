<?php

namespace App\Services\Field;

/**
 * Optimizador de rutas de campo (sin servicios externos):
 *   1) vecino más cercano desde el punto de inicio,
 *   2) mejora 2-opt sobre la ruta abierta (el inicio queda fijo).
 *
 *   $r = app(RouteOptimizer::class)->optimize($points, ['lat' => 20.7, 'lng' => -100.4]);
 *   $r['order']        // puntos ordenados (cada uno con 'leg_km' y 'sequence')
 *   $r['distance_km']  // distancia total en línea recta (haversine)
 *
 * Cada punto: ['key' => 'ticket:12', 'lat' => .., 'lng' => .., 'rank' => 0..n (opcional), ...extra].
 * Con $byPriority = true se visitan primero los puntos de menor 'rank' (p.ej. críticos),
 * optimizando cada grupo de prioridad por separado y encadenándolos.
 * Los puntos sin coordenadas se agregan al final en su orden original.
 */
class RouteOptimizer
{
    public const PRIORITY_RANK = ['critica' => 0, 'alta' => 1, 'media' => 2, 'baja' => 3];

    public function optimize(array $points, ?array $start = null, bool $byPriority = false): array
    {
        $points = array_values($points);
        $located = array_values(array_filter($points, fn ($p) => $this->hasCoords($p)));
        $unlocated = array_values(array_filter($points, fn ($p) => ! $this->hasCoords($p)));

        if (! $start || ! $this->hasCoords($start)) {
            $start = $located[0] ?? null;
        }

        $ordered = [];
        if ($located) {
            $groups = $byPriority
                ? collect($located)->groupBy(fn ($p) => (int) ($p['rank'] ?? 9))->sortKeys()->map(fn ($g) => $g->values()->all())->all()
                : [$located];
            $cursor = $start;
            foreach ($groups as $group) {
                $route = $this->twoOpt($this->nearestNeighbor($group, $cursor), $cursor);
                $ordered = array_merge($ordered, $route);
                $cursor = end($route) ?: $cursor;
            }
        }

        // Distancias por tramo
        $total = 0.0;
        $prev = $start;
        foreach ($ordered as $i => $p) {
            $leg = $prev ? $this->dist($prev, $p) : 0.0;
            $ordered[$i]['leg_km'] = round($leg, 2);
            $total += $leg;
            $prev = $p;
        }
        foreach ($unlocated as $p) {
            $p['leg_km'] = null;
            $ordered[] = $p;
        }
        foreach ($ordered as $i => $p) {
            $ordered[$i]['sequence'] = $i + 1;
        }

        return [
            'order' => $ordered,
            'distance_km' => round($total, 2),
            'start' => $start ? ['lat' => (float) $start['lat'], 'lng' => (float) $start['lng']] : null,
            'unlocated' => count($unlocated),
        ];
    }

    /** Distancia total de una secuencia (abierta) desde un inicio opcional. */
    public function pathLength(array $points, ?array $start = null): float
    {
        $total = 0.0;
        $prev = $start && $this->hasCoords($start) ? $start : null;
        foreach ($points as $p) {
            if (! $this->hasCoords($p)) {
                continue;
            }
            if ($prev) {
                $total += $this->dist($prev, $p);
            }
            $prev = $p;
        }

        return round($total, 2);
    }

    protected function nearestNeighbor(array $points, ?array $start): array
    {
        $remaining = $points;
        $route = [];
        $current = $start;
        while ($remaining) {
            $bestIdx = 0;
            if ($current) {
                $best = INF;
                foreach ($remaining as $i => $p) {
                    $d = $this->dist($current, $p);
                    if ($d < $best) {
                        $best = $d;
                        $bestIdx = $i;
                    }
                }
            }
            $current = $remaining[$bestIdx];
            $route[] = $current;
            array_splice($remaining, $bestIdx, 1);
        }

        return $route;
    }

    /** 2-opt para ruta abierta con inicio fijo (no se regresa al origen). */
    protected function twoOpt(array $route, ?array $start): array
    {
        $n = count($route);
        if ($n < 3) {
            return $route;
        }
        // Nodo 0 = inicio (o el primer punto si no hay inicio)
        $nodes = $start ? array_merge([$start], $route) : $route;
        $offset = $start ? 1 : 0;
        $m = count($nodes);

        $d = [];
        for ($i = 0; $i < $m; $i++) {
            for ($j = $i; $j < $m; $j++) {
                $d[$i][$j] = $d[$j][$i] = $i === $j ? 0.0 : $this->dist($nodes[$i], $nodes[$j]);
            }
        }

        $path = range(0, $m - 1);
        $improved = true;
        $guard = 0;
        while ($improved && $guard++ < 200) {
            $improved = false;
            for ($i = max(1, $offset); $i < $m - 1; $i++) {
                for ($k = $i + 1; $k < $m; $k++) {
                    $a = $path[$i - 1];
                    $b = $path[$i];
                    $c = $path[$k];
                    $before = $d[$a][$b];
                    $after = $d[$a][$c];
                    if ($k + 1 < $m) {
                        $e = $path[$k + 1];
                        $before += $d[$c][$e];
                        $after += $d[$b][$e];
                    }
                    if ($after + 1e-9 < $before) {
                        $segment = array_reverse(array_slice($path, $i, $k - $i + 1));
                        array_splice($path, $i, $k - $i + 1, $segment);
                        $improved = true;
                    }
                }
            }
        }

        $out = [];
        foreach ($path as $idx) {
            if ($idx < $offset) {
                continue;
            }
            $out[] = $nodes[$idx];
        }

        return $out;
    }

    protected function dist(array $a, array $b): float
    {
        return haversine_km((float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng']);
    }

    protected function hasCoords(?array $p): bool
    {
        return $p && isset($p['lat'], $p['lng']) && $p['lat'] !== '' && $p['lng'] !== '' && (float) $p['lat'] != 0.0;
    }
}
