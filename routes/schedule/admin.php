<?php

use Illuminate\Support\Facades\Schedule;

// Facturación SaaS: el día 1 de cada mes se facturan suscripción + comisiones del mes anterior (idempotente).
Schedule::command('saas:invoices')->monthlyOn(1, '02:00')->withoutOverlapping()->name('saas-invoices');

// Vigencia de suscripciones: suspende WISP con prueba terminada o suscripción vencida (+ días de gracia).
Schedule::command('saas:subscriptions')->dailyAt('04:00')->withoutOverlapping()->name('saas-subscriptions');

// CRM de ventas: recordatorio por correo a prospectos con demo en las próximas 24 horas.
Schedule::command('saas:demo-reminders')->everyFifteenMinutes()->withoutOverlapping()->name('saas-demo-reminders');

// Respaldo diario de la base de datos (se conservan los últimos 14; descarga desde Panel SaaS → Sistema).
Schedule::command('platform:backup')->dailyAt('03:00')->withoutOverlapping()->name('platform-backup');
// Respaldo semanal de archivos subidos (fotos de evidencia, firmas, logos): se conservan los últimos 4.
Schedule::command('platform:backup --files --reason=semanal')->weeklyOn(0, '03:30')->withoutOverlapping()->name('platform-backup-files');
