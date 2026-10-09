<?php

use Illuminate\Support\Facades\Schedule;

/*
| Operación (tickets / SLA / inventario)
*/

Schedule::command('operations:check-sla')
    ->everyFiveMinutes()->withoutOverlapping(10)->name('operations-check-sla');
