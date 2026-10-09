<?php

namespace App\Http\Controllers;

use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instalador web para hosting sin terminal (cPanel).
 * Se habilita con INSTALLER_ENABLED=true en .env y se bloquea solo en cuanto
 * existe un superadministrador. Ejecuta migraciones, crea el superadmin y el enlace de storage.
 */
class InstallController extends Controller
{
    protected function guard(): void
    {
        abort_unless((bool) config('wisp.installer_enabled'), 404);
        try {
            if (Schema::hasTable('users') && User::where('role', 'superadmin')->exists()) {
                abort(403, 'La plataforma ya está instalada. Desactiva INSTALLER_ENABLED en .env.');
            }
        } catch (\Illuminate\Database\QueryException) {
            // Sin conexión aún: se mostrará en los requisitos
        }
    }

    public function show()
    {
        $this->guard();

        return view('install.index', ['checks' => $this->checks()]);
    }

    public function run(Request $request)
    {
        $this->guard();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:150',
            'password' => 'required|string|min:10|confirmed',
            'demo' => 'nullable|boolean',
        ]);

        $failed = collect($this->checks())->where('required', true)->where('ok', false);
        if ($failed->isNotEmpty()) {
            return back()->withErrors(['email' => 'Corrige los requisitos marcados antes de instalar.']);
        }

        @set_time_limit(600);
        $log = [];
        Artisan::call('migrate', ['--force' => true]);
        $log[] = trim(Artisan::output());

        if ($request->boolean('demo')) {
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true]);
            $log[] = 'Datos de demostración cargados.';
        } else {
            (new ProductionSeeder)->run();
        }
        User::updateOrCreate(['role' => 'superadmin'], ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'active' => true]);

        try {
            if (! file_exists(public_path('storage'))) {
                Artisan::call('storage:link');
                $log[] = trim(Artisan::output());
            }
        } catch (\Throwable $e) {
            $log[] = 'No se pudo crear el enlace public/storage ('.$e->getMessage().'). Créalo manualmente (ver docs/DESPLIEGUE.md).';
        }
        foreach (['config:cache', 'route:cache', 'view:cache'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable) {
            }
        }

        return view('install.done', ['log' => $log, 'email' => $data['email']]);
    }

    protected function checks(): array
    {
        $db = null;
        $dbVersion = null;
        try {
            $dbVersion = DB::selectOne('select version() as v')->v;
            $db = true;
        } catch (\Throwable $e) {
            $db = $e->getMessage();
        }
        $mysqlOk = $dbVersion && (str_contains(strtolower($dbVersion), 'mariadb') ? version_compare($dbVersion, '10.2', '>=') : version_compare($dbVersion, '5.7.8', '>='));

        $ext = fn ($e) => extension_loaded($e);

        return [
            ['label' => 'PHP 8.2 o superior (actual '.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'required' => true],
            ['label' => 'Extensión pdo_mysql', 'ok' => $ext('pdo_mysql'), 'required' => true],
            ['label' => 'Extensión mbstring', 'ok' => $ext('mbstring'), 'required' => true],
            ['label' => 'Extensión openssl', 'ok' => $ext('openssl'), 'required' => true],
            ['label' => 'Extensión fileinfo', 'ok' => $ext('fileinfo'), 'required' => true],
            ['label' => 'Extensión dom / xml', 'ok' => $ext('dom'), 'required' => true],
            ['label' => 'Extensión curl (pasarelas, WhatsApp)', 'ok' => $ext('curl'), 'required' => true],
            ['label' => 'Extensión zip (exportar Excel)', 'ok' => $ext('zip'), 'required' => false],
            ['label' => 'Extensión gd (imágenes en PDF)', 'ok' => $ext('gd'), 'required' => false],
            ['label' => 'storage/ con permisos de escritura', 'ok' => is_writable(storage_path()), 'required' => true],
            ['label' => 'bootstrap/cache con permisos de escritura', 'ok' => is_writable(base_path('bootstrap/cache')), 'required' => true],
            ['label' => 'APP_KEY configurada', 'ok' => (bool) config('app.key'), 'required' => true],
            ['label' => 'Conexión a la base de datos'.($db === true ? '' : ': '.$db), 'ok' => $db === true, 'required' => true],
            ['label' => 'MySQL 5.7.8+ / MariaDB 10.2+ (actual '.($dbVersion ?? '—').')', 'ok' => (bool) $mysqlOk, 'required' => true],
            ['label' => 'Funciones de red salientes (MikroTik API / pasarelas)', 'ok' => function_exists('stream_socket_client'), 'required' => false],
        ];
    }
}
