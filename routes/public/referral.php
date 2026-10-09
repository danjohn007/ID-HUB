<?php

use App\Http\Controllers\Public\ReferralController;
use Illuminate\Support\Facades\Route;

/*
| Enlace público de referido (sección 29): /r/INVITA-5821. El interesado deja sus datos
| y queda registrado como prospecto del WISP con el código del cliente que lo invitó.
*/

Route::get('/r/{code}', [ReferralController::class, 'show'])->name('referral.show');
Route::post('/r/{code}', [ReferralController::class, 'store'])->middleware('throttle:10,1')->name('referral.store');
