<?php

use Illuminate\Support\Facades\Schedule;

// Módulo de red: monitoreo MikroTik, fallas, anomalías y mantenimientos
Schedule::command('network:poll')->everyFiveMinutes()->withoutOverlapping(10)->name('network-poll');
Schedule::command('network:anomalies')->hourlyAt(7)->withoutOverlapping()->name('network-anomalies');
Schedule::command('network:maintenances')->everyFifteenMinutes()->withoutOverlapping()->name('network-maintenances');
Schedule::command('network:prune --days=30')->dailyAt('03:20')->name('network-prune');
