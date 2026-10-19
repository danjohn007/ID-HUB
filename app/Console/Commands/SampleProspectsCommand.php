<?php

namespace App\Console\Commands;

use App\Services\SampleProspects;
use Illuminate\Console\Command;

/**
 * Prospectos de ejemplo del CRM de ventas (correo @prospecto-demo.mx). No envía correos.
 *   php artisan saas:sample-prospects           genera (reemplaza los de ejemplo anteriores)
 *   php artisan saas:sample-prospects --clear   solo los borra
 */
class SampleProspectsCommand extends Command
{
    protected $signature = 'saas:sample-prospects {--clear : Solo eliminar los prospectos de ejemplo}';

    protected $description = 'Genera o elimina prospectos de ejemplo en el CRM de ventas';

    public function handle(SampleProspects $samples): int
    {
        if ($this->option('clear')) {
            $this->info('Prospectos de ejemplo eliminados: '.$samples->clear());

            return self::SUCCESS;
        }
        $this->info('Prospectos de ejemplo generados: '.$samples->generate());

        return self::SUCCESS;
    }
}
