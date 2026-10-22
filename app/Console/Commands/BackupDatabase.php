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
    protected $signature = 'platform:backup {--reason=diario : Etiqueta en el nombre del archivo} {--files : Respalda también los archivos subidos (fotos, firmas, logos) en un .zip}';

    protected $description = 'Respalda la base de datos en storage/app/private/backups (.sql.gz)';

    public function handle(DatabaseBackup $backup): int
    {
        $start = microtime(true);
        $b = $backup->create((string) preg_replace('/[^\w-]/', '', $this->option('reason')) ?: 'diario');
        $this->info(sprintf('Respaldo %s · %d tablas · %s · %.1fs', $b['path'], $b['tables'], \Illuminate\Support\Number::fileSize($b['size'], 1), microtime(true) - $start));
        if ($this->option('files')) {
            $f = $backup->createFiles();
            $this->info($f['path'] ? sprintf('Archivos %s · %d archivos · %s', $f['path'], $f['files'], \Illuminate\Support\Number::fileSize($f['size'], 1)) : 'Sin archivos que respaldar');
        }
        if (config('wisp.backup_ftp.host')) {
            $this->line('Copia externa por FTP: '.config('wisp.backup_ftp.host'));
        }

        return self::SUCCESS;
    }
}
