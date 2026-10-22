<?php

namespace App\Services\Platform;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;

/**
 * Respaldo de la base de datos en PHP puro (sin mysqldump ni exec, que suelen estar
 * deshabilitados en cPanel). Genera un .sql.gz importable desde phpMyAdmin o con
 * `mysql < archivo.sql`. Se guarda fuera de public (storage/app/private/backups).
 */
class DatabaseBackup
{
    public const DIR = 'backups';

    /** Filas por INSERT (sentencias extendidas, como mysqldump). */
    protected const ROWS_PER_INSERT = 250;

    /** Respaldos que se conservan; los más antiguos se eliminan. */
    public const KEEP = 14;

    /** Respaldos de archivos (fotos, firmas, logos) que se conservan. */
    public const KEEP_FILES = 4;

    /** Genera el respaldo y devuelve [path relativo al disco local, bytes, tablas]. */
    public function create(string $reason = 'manual'): array
    {
        @set_time_limit(0);
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);

        $gz = function_exists('gzopen');
        $name = self::DIR.'/wispcontrol-'.now()->format('Ymd-His').'-'.$reason.'.sql'.($gz ? '.gz' : '');
        $file = $disk->path($name);
        $h = $gz ? gzopen($file, 'wb6') : fopen($file, 'wb');
        $write = $gz ? fn (string $s) => gzwrite($h, $s) : fn (string $s) => fwrite($h, $s);

        $pdo = DB::connection()->getPdo();
        $tables = collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))->map(fn ($r) => array_values((array) $r)[0]);

        try {
            $write('-- ID-Hub · respaldo de '.DB::connection()->getDatabaseName().' · '.now()->format('d/m/Y H:i:s')." · {$reason}\n");
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($tables as $table) {
                $create = array_values((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))[1];
                $write("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
                $this->dumpRows($pdo, $table, $write);
            }

            $write("SET FOREIGN_KEY_CHECKS=1;\n-- Fin del respaldo\n");
        } catch (\Throwable $e) {
            $gz ? gzclose($h) : fclose($h);
            $disk->delete($name);

            throw $e;
        }
        $gz ? gzclose($h) : fclose($h);

        $this->prune();
        $this->upload($name);

        return ['path' => $name, 'size' => $disk->size($name), 'tables' => $tables->count()];
    }

    /**
     * Respaldo de los archivos subidos (storage/app/public y storage/app/private, sin los propios
     * respaldos) en un .zip. Devuelve [path, bytes, archivos].
     */
    public function createFiles(string $reason = 'archivos'): array
    {
        @set_time_limit(0);
        throw_unless(class_exists(\ZipArchive::class), \RuntimeException::class, 'La extensión zip de PHP no está activa.');
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $name = self::DIR.'/wispcontrol-'.now()->format('Ymd-His').'-'.$reason.'.zip';

        $zip = new \ZipArchive;
        throw_unless($zip->open($disk->path($name), \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true, \RuntimeException::class, 'No se pudo crear el archivo zip.');
        $count = 0;
        foreach (['public' => storage_path('app/public'), 'private' => storage_path('app/private')] as $prefix => $root) {
            if (! is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($prefix === 'private' && str_starts_with($relative, self::DIR.'/') || $file->getFilename() === '.gitignore') {
                    continue;
                }
                $zip->addFile($file->getPathname(), $prefix.'/'.$relative);
                $count++;
            }
        }
        $zip->close();
        if (! $disk->exists($name)) {
            // ZipArchive no crea el archivo cuando no hay nada que agregar.
            return ['path' => null, 'size' => 0, 'files' => 0];
        }

        $this->pruneFiles();
        $this->upload($name);

        return ['path' => $name, 'size' => $disk->size($name), 'files' => $count];
    }

    /**
     * Copia externa por FTP/FTPS (BACKUP_FTP_HOST...). Sin configurar no hace nada. Un fallo se
     * registra en el log sin romper el respaldo local.
     */
    public function upload(string $name): bool
    {
        $cfg = config('wisp.backup_ftp');
        if (empty($cfg['host'])) {
            return false;
        }
        try {
            throw_unless(function_exists('ftp_connect'), \RuntimeException::class, 'La extensión ftp de PHP no está activa.');
            $conn = ! empty($cfg['ssl']) && function_exists('ftp_ssl_connect')
                ? ftp_ssl_connect($cfg['host'], (int) $cfg['port'], 30)
                : ftp_connect($cfg['host'], (int) $cfg['port'], 30);
            throw_unless($conn && @ftp_login($conn, (string) $cfg['username'], (string) $cfg['password']), \RuntimeException::class, 'No se pudo iniciar sesión en el FTP.');
            ftp_pasv($conn, true);
            $dir = trim((string) $cfg['path'], '/');
            $target = ($dir !== '' ? '/'.$dir.'/' : '').basename($name);
            $ok = ftp_put($conn, $target, Storage::disk('local')->path($name), FTP_BINARY);
            ftp_close($conn);
            throw_unless($ok, \RuntimeException::class, "No se pudo subir {$target}.");

            return true;
        } catch (\Throwable $e) {
            report(new \RuntimeException('Respaldo externo (FTP): '.$e->getMessage(), 0, $e));

            return false;
        }
    }

    /** Filas de la tabla en INSERTs extendidos, leyendo sin buffer para no cargar toda la tabla en memoria. */
    protected function dumpRows(PDO $pdo, string $table, callable $write): void
    {
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $pdo->query("SELECT * FROM `{$table}`", PDO::FETCH_NUM);
            $columns = null;
            $batch = [];
            foreach ($stmt as $row) {
                if ($columns === null) {
                    $columns = collect(range(0, $stmt->columnCount() - 1))
                        ->map(fn ($i) => '`'.$stmt->getColumnMeta($i)['name'].'`')->join(', ');
                }
                $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $write("INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $write("INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
            $stmt->closeCursor();
            $write("\n");
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /** Respaldos existentes, del más reciente al más antiguo. */
    public function list(): Collection
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIR))
            ->filter(fn ($f) => str_ends_with($f, '.sql') || str_ends_with($f, '.sql.gz') || str_ends_with($f, '.zip'))
            ->map(fn ($f) => (object) ['path' => $f, 'name' => basename($f), 'size' => $disk->size($f), 'date' => Carbon::createFromTimestamp($disk->lastModified($f), config('app.timezone'))])
            ->sortByDesc('date')->values();
    }

    public function prune(int $keep = self::KEEP): void
    {
        $this->list()->reject(fn ($b) => str_ends_with($b->name, '.zip'))->slice($keep)->each(fn ($b) => Storage::disk('local')->delete($b->path));
    }

    public function pruneFiles(int $keep = self::KEEP_FILES): void
    {
        $this->list()->filter(fn ($b) => str_ends_with($b->name, '.zip'))->slice($keep)->each(fn ($b) => Storage::disk('local')->delete($b->path));
    }

    /** Valida que el nombre sea un respaldo de la carpeta (evita rutas arbitrarias). */
    public function resolve(string $name): ?string
    {
        $path = self::DIR.'/'.basename($name);

        return preg_match('/^wispcontrol-[\w-]+\.(sql(\.gz)?|zip)$/', basename($name)) && Storage::disk('local')->exists($path) ? $path : null;
    }
}
