<?php

namespace App\Support;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire genera su endpoint de actualización relativo a la raíz del dominio ("/livewire/update").
 * Si la app se sirve desde una subcarpeta (p. ej. /ID-HUB/wispcontrol/public) hay que anteponerla,
 * o el navegador envía las peticiones a otro sitio y los formularios no responden.
 */
class LivewireHandleRequests extends HandleRequests
{
    public function getUpdateUri()
    {
        return request()->getBaseUrl().parent::getUpdateUri();
    }
}
