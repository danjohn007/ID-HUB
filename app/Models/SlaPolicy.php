<?php

namespace App\Models;

class SlaPolicy extends TenantModel
{
    /** Minutos objetivo para una prioridad (política del WISP o default del catálogo). */
    public static function minutesFor(string $priority): int
    {
        $policy = static::query()->where('priority', $priority)->value('resolution_minutes');

        return (int) ($policy ?? config("wisp.ticket_priorities.$priority.2", 480));
    }
}
