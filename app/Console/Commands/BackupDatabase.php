<?php

namespace App\Console\Commands;

use App\Services\Platform\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * Respaldo diario de la base de datos (routes/schedule/admin.php). Conserva los últimos
 * DatabaseBackup::KEEP archivos en storage/app/private/backups; se descargan desde Sistema.
 */
class BackupDatabase extends Command
{
    protected $signature = 'platform:backup {--reason=diario : Etiqueta en el nombre del archivo}';

    protected $description = 'Respalda la base de datos en storage/app/private/backups (.sql.gz)';

    public function handle(DatabaseBackup $backup): int
    {
        $start = microtime(true);
        $b = $backup->create((string) preg_replace('/[^\w-]/', '', $this->option('reason')) ?: 'diario');
        $this->info(sprintf('Respaldo %s · %d tablas · %s · %.1fs', $b['path'], $b['tables'], \Illuminate\Support\Number::fileSize($b['size'], 1), microtime(true) - $start));

        return self::SUCCESS;
    }
}
