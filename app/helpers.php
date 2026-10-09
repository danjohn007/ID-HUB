<?php

use App\Models\Tenant;
use App\Support\TenantContext;

if (! function_exists('tenant')) {
    /** WISP activo en el contexto actual. */
    function tenant(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}

if (! function_exists('money')) {
    /** Formatea un importe: money(1234.5) => "$1,234.50" */
    function money(float|int|string|null $amount, bool $decimals = true): string
    {
        $amount = (float) $amount;
        $s = number_format(abs($amount), $decimals ? 2 : 0);

        return ($amount < 0 ? '-$' : '$').$s;
    }
}

if (! function_exists('pct')) {
    function pct(float|int|null $value, int $decimals = 1): string
    {
        return number_format((float) $value, $decimals).'%';
    }
}

if (! function_exists('initials')) {
    function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }
}

if (! function_exists('haversine_km')) {
    /** Distancia en km entre dos coordenadas. */
    function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

if (! function_exists('next_folio')) {
    /**
     * Siguiente folio consecutivo por WISP para una tabla. next_folio('tickets', 'T') => "T-001258"
     */
    function next_folio(string $table, string $prefix, string $column = 'folio', int $pad = 6): string
    {
        $tenantId = app(TenantContext::class)->id();
        $last = \Illuminate\Support\Facades\DB::table($table)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where($column, 'like', $prefix.'-%')
            ->orderByDesc('id')->value($column);
        $n = $last ? ((int) substr($last, strlen($prefix) + 1)) + 1 : 1;

        return $prefix.'-'.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
    }
}
