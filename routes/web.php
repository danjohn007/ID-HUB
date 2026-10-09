<?php

use App\Livewire\Auth\Login;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
| - routes/public/*.php   : rutas públicas (pago en línea, webhooks, portal cautivo, agente MikroTik)
| - routes/saas/*.php     : panel del superadministrador (auth, sin WISP)
| - routes/modules/*.php  : módulos del WISP (auth + WISP activo). Cada módulo tiene su archivo.
*/

foreach (glob(__DIR__.'/public/*.php') as $file) {
    require $file;
}

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', function (Request $request) {
        Audit::log('logout', 'cerró sesión', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::get('/', fn () => redirect()->route(auth()->user()->homeRoute()))->name('home');

    Route::middleware('can:saas.manage')->prefix('saas')->group(function () {
        foreach (glob(__DIR__.'/saas/*.php') as $file) {
            require $file;
        }
    });

    Route::middleware('tenant')->group(function () {
        foreach (glob(__DIR__.'/modules/*.php') as $file) {
            require $file;
        }
    });
});
