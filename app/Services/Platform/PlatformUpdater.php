<?php

namespace App\Services\Platform;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Actualización de la base de datos desde el panel SaaS (cPanel sin terminal):
 * respaldo → migraciones pendientes → caché de configuración/rutas/vistas.
 * En MySQL 5.7 los cambios de esquema no son transaccionales, por eso siempre se
 * respalda antes y la ejecución es exclusiva (un solo proceso a la vez).
 */
class PlatformUpdater
{
    public function __construct(protected Migrator $migrator, protected DatabaseBackup $backup) {}

    /** Migraciones del código con su estado: [name, ran, batch]. */
    public function migrations(): Collection
    {
        $files = $this->migrator->getMigrationFiles(array_merge([database_path('migrations')], $this->migrator->paths()));
        $ran = $this->migrator->repositoryExists()
            ? DB::table(config('database.migrations.table', 'migrations'))->pluck('batch', 'migration')
            : collect();

        return collect(array_keys($files))->map(fn ($name) => (object) [
            'name' => $name, 'ran' => $ran->has($name), 'batch' => $ran[$name] ?? null,
        ]);
    }

    public function pending(): Collection
    {
        return $this->migrations()->reject->ran->values();
    }

    /**
     * Ejecuta la actualización completa. Devuelve [ok, backup, log[]]; nunca lanza excepción
     * para que el panel pueda mostrar el resultado aunque falle una migración.
     */
    public function run(): array
    {
        $lock = Cache::lock('platform-update', 900);
        if (! $lock->get()) {
            return ['ok' => false, 'backup' => null, 'log' => ['Ya hay una actualización en curso. Espera unos minutos y recarga la página.']];
        }

        @set_time_limit(0);
        $log = [];
        $backup = null;
        try {
            $backup = $this->backup->create('pre-migracion');
            $log[] = "Respaldo creado: {$backup['path']} ({$backup['tables']} tablas).";

            Artisan::call('migrate', ['--force' => true]);
            $log[] = trim(Artisan::output()) ?: 'Sin migraciones pendientes.';

            // En producción se regeneran las cachés; en local solo se limpian para no fijar el .env
            Artisan::call(app()->isProduction() ? 'optimize' : 'optimize:clear');
            $log[] = trim(Artisan::output());

            return ['ok' => true, 'backup' => $backup, 'log' => $log];
        } catch (\Throwable $e) {
            report($e);
            $log[] = 'ERROR: '.$e->getMessage();
            if ($backup) {
                $log[] = "Puedes restaurar el respaldo {$backup['path']} desde phpMyAdmin (Importar).";
            }

            return ['ok' => false, 'backup' => $backup, 'log' => $log];
        } finally {
            $lock->release();
        }
    }
}
