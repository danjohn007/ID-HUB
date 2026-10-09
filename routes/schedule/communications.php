<?php

use Illuminate\Support\Facades\Schedule;

/*
| Comunicación, campañas y alertas inteligentes.
*/

// Campañas programadas y envío por lotes (+ contadores de enviados/fallidos)
Schedule::command('campaigns:dispatch')->everyMinute()->withoutOverlapping(10)->name('campaigns-dispatch');

// Alertas inteligentes: cancelaciones, crecimiento por zona, desconexiones masivas, retrasos recurrentes
Schedule::command('alerts:smart')->hourly()->withoutOverlapping(30)->name('alerts-smart');
