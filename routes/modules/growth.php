<?php

/*
| Módulo Crecimiento: prospectos, instalaciones, mapa inteligente, planes,
| promociones, referidos y portal cautivo (secciones 5, 16-20, 28, 29, 40, 53).
*/

use App\Livewire\Installations;
use App\Livewire\Prospects;
use Illuminate\Support\Facades\Route;

Route::get('/prospectos', Prospects\Index::class)->middleware('can:prospects.manage')->name('prospects.index');

Route::get('/instalaciones', Installations\Index::class)->middleware('can:installations.manage')->name('installations.index');
// Acceso: installations.manage o técnico asignado (se valida en el componente)
Route::get('/instalaciones/{order}', Installations\Show::class)->name('installations.show');

Route::get('/mapa', App\Livewire\Map\Index::class)->middleware('can:map.view')->name('map.index');

Route::get('/planes', App\Livewire\Plans\Index::class)->middleware('can:plans.manage')->name('plans.index');

Route::middleware('can:growth.manage')->group(function () {
    Route::get('/promociones', App\Livewire\Growth\Promotions::class)->name('growth.promotions');
    Route::get('/referidos', App\Livewire\Growth\Referrals::class)->name('growth.referrals');
});

Route::get('/portal-cautivo', App\Livewire\Captive\Index::class)->middleware('can:captive.manage')->name('captive.index');
