<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base de los recursos de la API v1: helpers de formato (fechas ISO, montos, catálogos).
 */
abstract class ApiResource extends JsonResource
{
    protected static function money($value): float
    {
        return round((float) $value, 2);
    }

    protected static function date($value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toDateString() : null;
    }

    protected static function datetime($value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;
    }

    /** Valor + etiqueta en español de un catálogo de config/wisp.php ([etiqueta, color] o etiqueta). */
    protected static function catalog(string $catalog, ?string $value): array
    {
        $entry = $value === null ? null : config("wisp.$catalog.$value");

        return [
            'value' => $value,
            'label' => is_array($entry) ? $entry[0] : ($entry ?? $value),
            'color' => is_array($entry) ? ($entry[1] ?? null) : null,
        ];
    }

    protected static function coords($lat, $lng): ?array
    {
        return $lat !== null && $lng !== null ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;
    }
}
