<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Hora real de una acción de la app de campo. Lo que se hace sin señal se guarda en el teléfono y se
 * envía después con `occurred_at` (hora del teléfono): llegadas, salidas, estados, firmas, cobros y
 * visitas quedan con la hora en que ocurrieron y no con la hora en que se subieron.
 * Sin el campo, o con una hora fuera de rango (reloj del teléfono mal puesto), es now().
 */
class FieldClock
{
    /** Máximo atraso aceptado: lo guardado en el teléfono hace más tiempo se registra con la hora del servidor. */
    public const MAX_AGE_DAYS = 30;

    public static function now(): Carbon
    {
        return self::reported() ?? now();
    }

    /** Hora enviada por la app, si es válida (no futura, tolerando 5 min de desfase, ni de hace más de 30 días). */
    public static function reported(): ?Carbon
    {
        $raw = app()->bound('request') ? request()->input('occurred_at') : null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $at = Carbon::parse($raw)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
        $now = now();
        if ($at->gt($now->copy()->addMinutes(5)) || $at->lt($now->copy()->subDays(self::MAX_AGE_DAYS))) {
            return null;
        }

        return $at->min($now);
    }
}
