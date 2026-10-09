<?php

use App\Livewire\Billing;
use App\Livewire\Field\Collection as FieldCollection;
use Illuminate\Support\Facades\Route;

// Cobranza, pagos y cobradores (secciones 6, 7, 26, 49)
Route::middleware('can:billing.view')->prefix('cobranza')->name('billing.')->group(function () {
    Route::get('/', Billing\Index::class)->name('index');
    Route::get('/pagos', Billing\Payments::class)->name('payments');
    Route::get('/cobradores', Billing\Collections::class)->name('collections');
});

// Cortes de caja (sección 27) — supervisor/finanzas
Route::get('/cobranza/cortes', Billing\Cash::class)->middleware('can:cash.reconcile')->name('billing.cash');

// App de campo del cobrador (sección 26)
Route::get('/campo/cobranza', FieldCollection::class)->middleware('can:collection.field')->name('field.collection');
