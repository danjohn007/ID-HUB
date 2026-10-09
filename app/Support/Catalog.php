<?php

namespace App\Support;

/**
 * Acceso a catálogos de config/wisp.php.
 * Las entradas pueden ser 'clave' => 'Etiqueta' o 'clave' => ['Etiqueta', 'color', ...].
 */
class Catalog
{
    public static function all(string $catalog): array
    {
        return config("wisp.$catalog", []);
    }

    public static function options(string $catalog): array
    {
        return collect(static::all($catalog))->map(fn ($v) => is_array($v) ? $v[0] : $v)->all();
    }

    public static function label(string $catalog, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }
        $v = static::all($catalog)[$key] ?? null;

        return $v === null ? ucfirst(str_replace('_', ' ', $key)) : (is_array($v) ? $v[0] : $v);
    }

    public static function color(string $catalog, ?string $key, string $default = 'slate'): string
    {
        $v = static::all($catalog)[$key] ?? null;

        return is_array($v) ? ($v[1] ?? $default) : $default;
    }

    public static function extra(string $catalog, ?string $key, int $index = 2, mixed $default = null): mixed
    {
        $v = static::all($catalog)[$key] ?? null;

        return is_array($v) ? ($v[$index] ?? $default) : $default;
    }
}
