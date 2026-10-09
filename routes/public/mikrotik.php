<?php

use App\Http\Controllers\MikroTikPushController;
use Illuminate\Support\Facades\Route;

// Agente push de MikroTik (CSRF excluido para mikrotik/push/* en bootstrap/app.php)
Route::prefix('mikrotik/push/{token}')->name('mikrotik.push.')->group(function () {
    Route::post('/report', [MikroTikPushController::class, 'report'])->name('report');
    Route::get('/commands', [MikroTikPushController::class, 'commands'])->name('commands');
});
