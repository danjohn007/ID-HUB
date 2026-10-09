<?php

use App\Http\Controllers\Public\CaptivePortalController;
use Illuminate\Support\Facades\Route;

/*
| Portal cautivo público por WISP (sección 28). El login.html del hotspot MikroTik redirige a
| /portal/{slug} con mac, ip, link-login-only y link-orig. Sin autenticación de usuario:
| se abre desde dispositivos que todavía no navegan.
*/

Route::prefix('portal/{slug}')->name('captive.')->group(function () {
    Route::get('/', [CaptivePortalController::class, 'show'])->name('portal');
    Route::post('/', [CaptivePortalController::class, 'login'])->middleware('throttle:20,1')->name('login');
    Route::get('/anuncio/{ad}', [CaptivePortalController::class, 'ad'])->whereNumber('ad')->middleware('throttle:60,1')->name('ad');
});
