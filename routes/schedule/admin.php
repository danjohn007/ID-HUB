<?php

use Illuminate\Support\Facades\Schedule;

// Facturación SaaS: el día 1 de cada mes se facturan suscripción + comisiones del mes anterior (idempotente).
Schedule::command('saas:invoices')->monthlyOn(1, '02:00')->withoutOverlapping()->name('saas-invoices');

// Respaldo diario de la base de datos (se conservan los últimos 14; descarga desde Panel SaaS → Sistema).
Schedule::command('platform:backup')->dailyAt('03:00')->withoutOverlapping()->name('platform-backup');
