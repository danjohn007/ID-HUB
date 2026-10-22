<?php

use App\Http\Controllers\EvidenceController;
use Illuminate\Support\Facades\Route;

/*
| Evidencias privadas (fotos, firmas y evidencia de cobro) del WISP activo.
| Requiere sesión del personal; la app de campo usa enlaces firmados de la API.
*/

Route::get('/evidencias/{path}', EvidenceController::class)->where('path', '.*')->name('evidence.show');
