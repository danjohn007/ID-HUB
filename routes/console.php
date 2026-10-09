<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Programador de tareas
|--------------------------------------------------------------------------
| En cPanel se configura UN solo cron cada minuto:
|   * * * * * /usr/local/bin/php /home/USUARIO/wispcontrol/artisan schedule:run >> /dev/null 2>&1
|
| Cada módulo registra sus tareas en routes/schedule/<modulo>.php.
| La cola (mensajes, campañas) se procesa aquí mismo sin necesidad de un worker permanente.
*/

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=2')
    ->everyMinute()->withoutOverlapping()->name('queue-worker');

Schedule::command('queue:prune-failed --hours=168')->daily();

foreach (glob(__DIR__.'/schedule/*.php') as $file) {
    require $file;
}
