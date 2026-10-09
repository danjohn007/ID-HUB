<?php

use App\Livewire\Field;
use App\Livewire\Inventory;
use App\Livewire\Routes;
use App\Livewire\Staff;
use App\Livewire\Tickets;
use Illuminate\Support\Facades\Route;

/*
| Operación: tickets + SLA, técnicos y cobradores, rutas, inventario y app de campo del técnico.
*/

Route::get('/tickets/sla', Tickets\Sla::class)->middleware('can:settings.manage')->name('tickets.sla');
Route::middleware('can:tickets.view')->group(function () {
    Route::get('/tickets', Tickets\Index::class)->name('tickets.index');
    Route::get('/tickets/{ticket}', Tickets\Show::class)->whereNumber('ticket')->name('tickets.show');
});

Route::middleware('can:staff.view')->group(function () {
    Route::get('/tecnicos', Staff\Index::class)->name('staff.index');
    Route::get('/tecnicos/{user}', Staff\Show::class)->whereNumber('user')->name('staff.show');
});

Route::get('/rutas', Routes\Index::class)->middleware('can:routes.manage')->name('routes.index');

Route::get('/inventario', Inventory\Index::class)->middleware('can:inventory.view')->name('inventory.index');

Route::middleware('can:field.work')->group(function () {
    Route::get('/campo', Field\Today::class)->name('field.today');
    Route::get('/campo/ticket/{ticket}', Field\Work::class)->whereNumber('ticket')->name('field.work');
});

Route::get('/mantenimientos', App\Livewire\Maintenances\Index::class)->middleware('can:network.manage')->name('maintenances.index');
