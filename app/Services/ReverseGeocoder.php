<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dirección a partir de coordenadas (geocodificación inversa) con Nominatim de OpenStreetMap:
 * gratuito y sin llave. Su política pide identificarse (User-Agent) y no más de 1 consulta por segundo,
 * por eso los resultados se guardan en caché por punto (~1 m).
 *
 *   app(ReverseGeocoder::class)->lookup(20.6487, -100.4577)
 *   => ['address' => 'Calle Hidalgo 12', 'colonia' => 'Centro', 'community' => 'Querétaro', 'full' => '...']  | null
 */
class ReverseGeocoder
{
    public function lookup(float $lat, float $lng): ?array
    {
        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        $key = sprintf('geo:reverse:%.5f,%.5f', $lat, $lng);
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => config('wisp.brand.name').' ('.config('wisp.geocoding.email', 'dan@impactosdigitales.com').')'])
                ->get(rtrim(config('wisp.geocoding.url') ?: 'https://nominatim.openstreetmap.org', '/').'/reverse', [
                    'lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'addressdetails' => 1, 'zoom' => 18, 'accept-language' => 'es',
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $a = $response->successful() ? (array) $response->json('address', []) : [];
        if (! $a) {
            return null;
        }

        $street = trim(($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? $a['path'] ?? '').' '.($a['house_number'] ?? ''));
        $colonia = $a['neighbourhood'] ?? $a['suburb'] ?? $a['quarter'] ?? $a['residential'] ?? $a['city_district'] ?? null;
        $community = $a['village'] ?? $a['hamlet'] ?? $a['town'] ?? $a['city'] ?? $a['municipality'] ?? null;

        $result = array_map(fn ($v) => $v ? $this->clean($v) : null, [
            'address' => $street ?: null,
            'colonia' => $colonia,
            'community' => $community,
            'full' => implode(', ', array_unique(array_filter([
                $street, $colonia, isset($a['postcode']) ? 'C.P. '.$a['postcode'] : null, $community, $a['state'] ?? null,
            ]))),
        ]);

        Cache::put($key, $result, now()->addDays(30));

        return $result;
    }

    /** Solo caracteres que acepta InputRules::TEXT (las direcciones de OSM pueden traer "–", "·", etc.). */
    protected function clean(string $value): string
    {
        $value = strtr($value, ['–' => '-', '—' => '-', '·' => ',', '’' => "'", '“' => '"', '”' => '"']);
        $value = preg_replace('/[^\pL\pN\s.,;:_#&()\/\'"!¡?¿°%@+\-]/u', '', $value);

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, 190);
    }
}
