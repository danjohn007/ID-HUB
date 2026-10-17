<?php

namespace App\Console\Commands;

use App\Services\DemoScheduler;
use Illuminate\Console\Command;

/**
 * Recordatorio por correo a los prospectos con demo en las próximas 24 horas (CRM de ventas).
 *   php artisan saas:demo-reminders
 */
class SendDemoReminders extends Command
{
    protected $signature = 'saas:demo-reminders';

    protected $description = 'Envía el recordatorio de las demos de las próximas 24 horas';

    public function handle(DemoScheduler $scheduler): int
    {
        $sent = $scheduler->sendReminders();
        $this->info("Recordatorios enviados: {$sent}");

        return self::SUCCESS;
    }
}
