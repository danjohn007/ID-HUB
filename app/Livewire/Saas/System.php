<?php

namespace App\Livewire\Saas;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Concerns\PlatformContext;
use App\Services\Platform\DatabaseBackup;
use App\Services\Platform\PlatformUpdater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sistema (superadmin): migraciones pendientes tras subir una actualización, botón
 * "Respaldar y actualizar" y respaldos de la base de datos (manuales, diarios y previos a migrar).
 */
#[Title('Sistema')]
class System extends Component
{
    use PlatformContext, Toasts;

    /** Resultado de la última actualización: [ok, log[]] */
    public ?array $result = null;

    public bool $showAll = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
    }

    public function update(PlatformUpdater $updater): void
    {
        $pending = $updater->pending()->pluck('name');
        $r = $updater->run();
        $this->result = ['ok' => $r['ok'], 'log' => $r['log']];
        $this->platformLog('platform_updated', ($r['ok'] ? 'actualizó' : 'intentó actualizar').' la base de datos ('.$pending->count().' migraciones)', null, null, [], ['migraciones' => $pending->all(), 'ok' => $r['ok']]);
        $this->toast($r['ok'] ? 'Base de datos actualizada' : 'La actualización falló. Revisa el detalle.', $r['ok'] ? 'success' : 'error');
    }

    public function backup(DatabaseBackup $backup): void
    {
        try {
            $b = $backup->create('manual');
        } catch (\Throwable $e) {
            report($e);
            $this->toast('No se pudo crear el respaldo: '.$e->getMessage(), 'error');

            return;
        }
        $this->platformLog('backup_created', 'creó un respaldo de la base de datos', null, null, [], ['archivo' => $b['path']]);
        $this->toast('Respaldo creado');
    }

    public function download(string $name, DatabaseBackup $backup)
    {
        $path = $backup->resolve($name);
        abort_unless($path, 404);
        $this->platformLog('backup_downloaded', 'descargó el respaldo '.basename($path));

        return Storage::disk('local')->download($path);
    }

    public function deleteBackup(string $name, DatabaseBackup $backup): void
    {
        $path = $backup->resolve($name);
        abort_unless($path, 404);
        Storage::disk('local')->delete($path);
        $this->platformLog('backup_deleted', 'eliminó el respaldo '.basename($path));
        $this->toast('Respaldo eliminado', 'warning');
    }

    public function render(PlatformUpdater $updater, DatabaseBackup $backup)
    {
        $migrations = $updater->migrations();

        return view('livewire.saas.system', [
            'migrations' => $this->showAll ? $migrations->reverse() : $migrations->reject->ran,
            'pendingCount' => $migrations->reject->ran->count(),
            'totalCount' => $migrations->count(),
            'backups' => $backup->list(),
            'keep' => DatabaseBackup::KEEP,
            'env' => [
                'PHP' => PHP_VERSION,
                'Laravel' => app()->version(),
                'Base de datos' => DB::connection()->getDatabaseName().' · '.DB::selectOne('SELECT VERSION() v')->v,
                'Entorno' => app()->environment(),
                'Caché de configuración' => app()->configurationIsCached() ? 'Activa' : 'Sin caché',
            ],
        ]);
    }
}
