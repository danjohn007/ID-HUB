<?php

use App\Http\Controllers\Api\V1\ApiDocsController;
use Illuminate\Support\Facades\Route;

/*
| Documentación pública de la API REST v1 (sección 50). Detalle en docs/API.md.
*/

Route::get('/api/docs', ApiDocsController::class)->name('api.docs');
