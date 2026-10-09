<?php

use App\Livewire\Network;
use Illuminate\Support\Facades\Route;

// Red: monitoreo, repetidores, equipos, MikroTik, torres y balanceo (secciones 10–14, 17, 18, 32)
Route::prefix('red')->name('network.')->group(function () {
    Route::middleware('can:network.view')->group(function () {
        Route::get('/', Network\Index::class)->name('index');
        Route::get('/repetidores', Network\Repeaters\Index::class)->name('repeaters.index');
        Route::get('/repetidores/{repeater}', Network\Repeaters\Show::class)->whereNumber('repeater')->name('repeaters.show');
        Route::get('/equipos', Network\Devices\Index::class)->name('devices.index');
        Route::get('/equipos/{device}', Network\Devices\Show::class)->whereNumber('device')->name('devices.show');
        Route::get('/torres', Network\Towers\Index::class)->name('towers.index');
    });

    Route::middleware('can:network.manage')->group(function () {
        Route::get('/mikrotik', Network\MikroTik\Index::class)->name('mikrotik.index');
        Route::get('/mikrotik/{device}', Network\MikroTik\Show::class)->whereNumber('device')->name('mikrotik.show');
        Route::get('/balanceo', Network\Balancing::class)->name('balancing');
    });
});
