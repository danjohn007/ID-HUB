<?php

use App\Livewire\Audit;
use App\Livewire\Bi;
use App\Livewire\Finance;
use App\Livewire\Profile;
use App\Livewire\Reports;
use App\Livewire\Settings;
use App\Livewire\Users;
use Illuminate\Support\Facades\Route;

/*
| Módulo de administración del WISP: finanzas, BI, reportes, auditoría,
| configuración, usuarios y perfil.
*/

Route::get('/finanzas', Finance\Index::class)->middleware('can:finance.view')->name('finance.index');
Route::get('/bi', Bi\Index::class)->middleware('can:bi.view')->name('bi.index');
Route::get('/reportes', Reports\Index::class)->middleware('can:reports.view')->name('reports.index');
Route::get('/auditoria', Audit\Index::class)->middleware('can:audit.view')->name('audit.index');
Route::get('/configuracion', Settings\Index::class)->middleware('can:settings.manage')->name('settings.index');
Route::get('/usuarios', Users\Index::class)->middleware('can:users.manage')->name('users.index');
Route::get('/perfil', Profile::class)->name('profile');
