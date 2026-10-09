<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Instalador web (solo con INSTALLER_ENABLED=true y sin superadmin existente)
Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::post('/install', [InstallController::class, 'run'])->middleware('throttle:5,1');
