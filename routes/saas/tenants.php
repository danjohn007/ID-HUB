<?php

use App\Livewire\Saas;
use Illuminate\Support\Facades\Route;

// Empresas WISP (sección 3): uso contra límites del plan, consumo y ficha de cada WISP
Route::get('/empresas', Saas\Tenants\Index::class)->name('saas.tenants.index');
Route::get('/empresas/{tenant}', Saas\Tenants\Show::class)->whereNumber('tenant')->name('saas.tenants.show');
