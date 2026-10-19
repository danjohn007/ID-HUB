<?php

use App\Http\Controllers\Public\MetaWhatsAppWebhookController;
use App\Http\Controllers\Public\TwilioStatusController;
use Illuminate\Support\Facades\Route;

/*
| Mensajería: avisos de estado de entrega de Twilio (SMS y WhatsApp) y de WhatsApp Cloud API (Meta).
| Sin autenticación: Twilio se valida con X-Twilio-Signature (Auth token del WISP) y Meta con
| X-Hub-Signature-256 (secreto de la app del WISP).
*/

Route::post('/webhooks/twilio/status', TwilioStatusController::class)
    ->middleware('throttle:600,1')
    ->name('messaging.twilio.status');

Route::prefix('/webhooks/whatsapp/{tenant}')->whereNumber('tenant')->middleware('throttle:600,1')->group(function () {
    Route::get('/', [MetaWhatsAppWebhookController::class, 'verify'])->name('messaging.meta.webhook');
    Route::post('/', [MetaWhatsAppWebhookController::class, 'handle']);
});
