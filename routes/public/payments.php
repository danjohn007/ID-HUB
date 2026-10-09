<?php

use App\Http\Controllers\Public\PaymentLinkController;
use App\Http\Controllers\Public\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Pagos en línea (sección 7): enlace público "Pagar ahora" y webhooks de pasarelas.
| Sin autenticación: el WISP se resuelve a partir del token del enlace.
*/

Route::prefix('pagar/{token}')->name('pay.')->group(function () {
    Route::get('/', [PaymentLinkController::class, 'show'])->name('show');
    Route::post('/', [PaymentLinkController::class, 'checkout'])->middleware('throttle:20,1')->name('checkout');
    Route::get('/exito', [PaymentLinkController::class, 'success'])->name('success');
    Route::get('/error', [PaymentLinkController::class, 'failure'])->name('failure');
    Route::get('/comprobante/{payment}', [PaymentLinkController::class, 'receipt'])->whereNumber('payment')->name('receipt');
    Route::get('/simulador/{provider}', [PaymentLinkController::class, 'sandbox'])->name('sandbox');
    Route::post('/simulador/{provider}', [PaymentLinkController::class, 'sandboxComplete'])->middleware('throttle:20,1')->name('sandbox.complete');
});

Route::post('/webhooks/{provider}', PaymentWebhookController::class)
    ->whereIn('provider', ['mercadopago', 'stripe', 'oxxo', 'paypal'])
    ->middleware('throttle:120,1')
    ->name('pay.webhook');
