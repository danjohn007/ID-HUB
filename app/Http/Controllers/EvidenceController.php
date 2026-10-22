<?php

namespace App\Http\Controllers;

use App\Support\Evidence;

/**
 * Sirve una evidencia privada al personal con sesión del mismo WISP
 * (la carpeta {tenant_id} de la ruta debe ser la del WISP activo).
 */
class EvidenceController extends Controller
{
    public function __invoke(string $path)
    {
        abort_unless(tenant(), 404);

        return Evidence::response($path, (int) tenant()->id);
    }
}
