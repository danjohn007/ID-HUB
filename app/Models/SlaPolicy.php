<?php

namespace App\Models;

class SlaPolicy extends TenantModel
{
    /** Prioridad de las políticas por categoría (tipo de incidencia). */
    public const ANY_PRIORITY = '*';

    /**
     * Minutos objetivo de un ticket: el de su prioridad (política del WISP o default del catálogo)
     * o, si su categoría tiene un tiempo propio más estricto, ese.
     */
    public static function minutesFor(string $priority, ?string $category = null): int
    {
        $minutes = (int) (static::query()->where('priority', $priority)->whereNull('category')->value('resolution_minutes')
            ?? config("wisp.ticket_priorities.$priority.2", 480));
        $byCategory = $category ? static::query()->where('priority', self::ANY_PRIORITY)->where('category', $category)->value('resolution_minutes') : null;

        return $byCategory ? min($minutes, (int) $byCategory) : $minutes;
    }
}
