<?php

use Illuminate\Support\Facades\Schedule;

/*
| Cobranza automática (secciones 6.2 y 8). Todas las tareas son idempotentes.
| Las horas de envío/corte se evalúan dentro de cada comando en la zona horaria del WISP
| (billing.reminder_hour, billing.cut_hour), por eso corren con frecuencia fija.
*/

Schedule::command('billing:generate-charges')->dailyAt('00:20')->withoutOverlapping()->name('billing-generate-charges');
Schedule::command('billing:reminders')->hourlyAt(5)->withoutOverlapping()->name('billing-reminders');
Schedule::command('billing:enforce-cutoffs')->everyFifteenMinutes()->withoutOverlapping()->name('billing-enforce-cutoffs');
Schedule::command('payments:expire-links')->dailyAt('01:10')->name('payments-expire-links');
