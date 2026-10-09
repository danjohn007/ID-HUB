<?php

namespace App\Services\Network;

use App\Models\NetworkDevice;
use App\Services\MikroTik\MikroTikService;

/**
 * Punto único para obtener el MikroTikService que usa el módulo de red.
 * En modo simulado utiliza DemoRouterTransport (datos de demo dinámicos).
 */
class NetworkClient
{
    public static function mikrotik(NetworkDevice $device): MikroTikService
    {
        $service = MikroTikService::for($device);
        if (! in_array($device->api_mode, ['api', 'rest', 'push'], true)) {
            $service->setTransport(new DemoRouterTransport($device));
        }

        return $service;
    }

    /** Mensaje de error legible para la interfaz. */
    public static function friendlyError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e instanceof \App\Services\MikroTik\MikroTikException) {
            return $msg;
        }
        if (str_contains($msg, 'timed out') || str_contains($msg, 'Connection timed out')) {
            return 'Tiempo de espera agotado al conectar con el equipo.';
        }
        if (str_contains($msg, 'Connection refused')) {
            return 'El equipo rechazó la conexión (verifica host, puerto y que el servicio API esté habilitado).';
        }

        return 'Error de comunicación con el equipo: '.mb_substr($msg, 0, 160);
    }
}
