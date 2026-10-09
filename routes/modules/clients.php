<?php

use App\Http\Controllers\DocumentController;
use App\Livewire\Clients;
use Illuminate\Support\Facades\Route;

Route::middleware('can:clients.view')->group(function () {
    Route::get('/clientes', Clients\Index::class)->name('clients.index');
    Route::get('/clientes/nuevo', Clients\Edit::class)->middleware('can:clients.manage')->name('clients.create');
    Route::get('/clientes/{client}', Clients\Show::class)->name('clients.show');
    Route::get('/clientes/{client}/editar', Clients\Edit::class)->middleware('can:clients.manage')->name('clients.edit');
});

// Documentos PDF
Route::prefix('documentos')->name('documents.')->group(function () {
    Route::get('/contrato/{client}', [DocumentController::class, 'contract'])->middleware('can:clients.view')->name('contract');
    Route::get('/estado-cuenta/{client}', [DocumentController::class, 'statement'])->middleware('can:clients.view')->name('statement');
    Route::get('/comprobante/{payment}', [DocumentController::class, 'receipt'])->name('receipt');
    Route::get('/instalacion/{order}', [DocumentController::class, 'installation'])->name('installation');
    Route::get('/servicio/{ticket}', [DocumentController::class, 'ticket'])->name('ticket');
});
