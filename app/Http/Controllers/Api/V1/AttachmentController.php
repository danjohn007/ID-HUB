<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\Evidence;

/**
 * Evidencias privadas para la app de campo: se descargan con el enlace firmado temporal
 * (30 min) que devuelven los recursos de tickets, instalaciones y cobranza.
 */
class AttachmentController extends ApiController
{
    public function file(string $path)
    {
        return Evidence::response($path);
    }
}
