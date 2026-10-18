<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ClientAppController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\FieldController;
use App\Http\Controllers\Api\V1\InstallationController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\NetworkController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\SignedDocumentController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Middleware\ApiAbility;
use App\Http\Middleware\ApiAuthenticate;
use App\Http\Middleware\ApiForceJson;
use App\Http\Middleware\ApiKeyAuthenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST v1 (sección 50) — prefijo /api/v1
|--------------------------------------------------------------------------
| Autenticación con tokens Sanctum (Authorization: Bearer <token>).
| - Personal:  POST /auth/login          → endpoints de staff (permisos de config/permissions.php)
| - Cliente:   POST /client-auth/login   → endpoints /client/* (app del cliente)
| - Integración servidor-a-servidor: X-Api-Key por WISP → /integration/*
| Funciones del plan SaaS: "api" (integraciones y administración), "app_tecnico"
| (apps de técnico / cobrador) y "app_cliente" (app del cliente).
| Documentación: docs/API.md y /api/docs
*/

$can = fn (string $ability) => ApiAbility::class.':'.$ability;

Route::prefix('v1')->name('api.v1.')->middleware([ApiForceJson::class, 'throttle:api'])->group(function () use ($can) {

    Route::get('/', fn () => response()->json([
        'name' => config('wisp.brand.name').' API',
        'version' => 'v1',
        'docs' => url('/api/docs'),
        'time' => now()->toIso8601String(),
    ]))->name('index');

    // ---------------- Autenticación ----------------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('auth.login');
    Route::post('client-auth/login', [AuthController::class, 'clientLogin'])->middleware('throttle:api-login')->name('client-auth.login');

    // Documentos PDF por enlace firmado (los URLs vienen en las respuestas de pagos / estado de cuenta)
    Route::middleware('signed')->group(function () {
        Route::get('receipts/{payment}', [SignedDocumentController::class, 'receipt'])->whereNumber('payment')->name('receipt');
        Route::get('statements/{client}', [SignedDocumentController::class, 'statement'])->whereNumber('client')->name('statement');
    });

    // Staff o cliente
    Route::middleware(ApiAuthenticate::class.':any')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('client-auth/logout', [AuthController::class, 'logout'])->name('client-auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });

    // ---------------- Apps de personal (técnico / cobrador / soporte) ----------------
    Route::middleware(ApiAuthenticate::class.':staff,api|app_tecnico')->group(function () use ($can) {
        Route::post('push-tokens', [PushTokenController::class, 'store'])->name('push-tokens.store');
        Route::delete('push-tokens', [PushTokenController::class, 'destroy'])->name('push-tokens.destroy');

        Route::middleware($can('clients.view'))->group(function () {
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::get('clients/{id}', [ClientController::class, 'show'])->whereNumber('id')->name('clients.show');
            Route::get('clients/{id}/statement', [ClientController::class, 'statement'])->whereNumber('id')->name('clients.statement');
            Route::get('plans', [BillingController::class, 'plans'])->name('plans.index');
            Route::get('plans/{id}', [BillingController::class, 'plan'])->whereNumber('id')->name('plans.show');
        });

        Route::middleware($can('tickets.view'))->group(function () use ($can) {
            Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
            Route::get('tickets/{id}', [TicketController::class, 'show'])->whereNumber('id')->name('tickets.show');
            Route::get('tickets/{id}/comments', [TicketController::class, 'comments'])->whereNumber('id')->name('tickets.comments');
            Route::post('tickets', [TicketController::class, 'store'])->middleware($can('tickets.manage'))->name('tickets.store');
            Route::post('tickets/{id}/assign', [TicketController::class, 'assign'])->whereNumber('id')->middleware($can('tickets.manage'))->name('tickets.assign');
            Route::middleware($can('tickets.manage|field.work'))->group(function () {
                Route::patch('tickets/{id}/status', [TicketController::class, 'updateStatus'])->whereNumber('id')->name('tickets.status');
                Route::post('tickets/{id}/comments', [TicketController::class, 'addComment'])->whereNumber('id')->name('tickets.comments.store');
                Route::post('tickets/{id}/attachments', [TicketController::class, 'addAttachment'])->whereNumber('id')->name('tickets.attachments.store');
                Route::delete('tickets/{id}/attachments/{attachment}', [TicketController::class, 'deleteAttachment'])->whereNumber(['id', 'attachment'])->name('tickets.attachments.destroy');
                Route::post('tickets/{id}/materials', [TicketController::class, 'addMaterial'])->whereNumber('id')->name('tickets.materials.store');
                Route::post('tickets/{id}/materials/{movement}/return', [TicketController::class, 'returnMaterial'])->whereNumber(['id', 'movement'])->name('tickets.materials.return');
                Route::post('tickets/{id}/signature', [TicketController::class, 'signature'])->whereNumber('id')->name('tickets.signature');
            });
        });

        // Catálogo de inventario para registrar materiales en campo
        Route::get('inventory/items', [InventoryController::class, 'items'])->middleware($can('field.work|inventory.view'))->name('inventory.items');

        // Órdenes de instalación del técnico (secciones 23 y 40)
        Route::prefix('installations')->name('installations.')->middleware($can('field.work|installations.manage'))->group(function () {
            Route::get('/', [InstallationController::class, 'index'])->name('index');
            Route::get('{id}', [InstallationController::class, 'show'])->whereNumber('id')->name('show');
            Route::post('{id}/start', [InstallationController::class, 'start'])->whereNumber('id')->name('start');
            Route::post('{id}/checklist', [InstallationController::class, 'checklist'])->whereNumber('id')->name('checklist');
            Route::post('{id}/measures', [InstallationController::class, 'measures'])->whereNumber('id')->name('measures');
            Route::post('{id}/attachments', [InstallationController::class, 'addAttachment'])->whereNumber('id')->name('attachments.store');
            Route::delete('{id}/attachments/{attachment}', [InstallationController::class, 'deleteAttachment'])->whereNumber(['id', 'attachment'])->name('attachments.destroy');
            Route::post('{id}/signature', [InstallationController::class, 'signature'])->whereNumber('id')->name('signature');
            Route::post('{id}/materials', [InstallationController::class, 'addMaterial'])->whereNumber('id')->name('materials.store');
            Route::post('{id}/materials/{movement}/return', [InstallationController::class, 'returnMaterial'])->whereNumber(['id', 'movement'])->name('materials.return');
            Route::post('{id}/complete', [InstallationController::class, 'complete'])->whereNumber('id')->name('complete');
        });

        Route::prefix('field')->name('field.')->middleware($can('field.work|collection.field'))->group(function () use ($can) {
            Route::get('today', [FieldController::class, 'today'])->name('today');
            Route::post('start-day', [FieldController::class, 'startDay'])->name('start-day');
            Route::post('stops/{stop}/skip', [FieldController::class, 'skip'])->whereNumber('stop')->name('stops.skip');
            Route::post('stops/{stop}/arrive', [FieldController::class, 'arrive'])->whereNumber('stop')->name('stops.arrive');
            Route::post('stops/{stop}/depart', [FieldController::class, 'depart'])->whereNumber('stop')->name('stops.depart');
            Route::post('location', [FieldController::class, 'location'])->name('location');
            Route::middleware($can('field.work'))->group(function () {
                Route::post('tickets/{ticket}/on-way', [FieldController::class, 'onWay'])->whereNumber('ticket')->name('tickets.on-way');
                Route::post('tickets/{ticket}/arrive', [FieldController::class, 'arriveTicket'])->whereNumber('ticket')->name('tickets.arrive');
                Route::post('tickets/{ticket}/depart', [FieldController::class, 'departTicket'])->whereNumber('ticket')->name('tickets.depart');
            });
        });

        Route::prefix('collection')->name('collection.')->middleware($can('collection.field'))->group(function () {
            Route::get('today', [CollectionController::class, 'today'])->name('today');
            Route::get('pending', [CollectionController::class, 'pending'])->name('pending');
            Route::get('clients/{id}', [CollectionController::class, 'client'])->whereNumber('id')->name('client');
            Route::get('clients/{id}/visits', [CollectionController::class, 'history'])->whereNumber('id')->name('history');
            Route::post('visits', [CollectionController::class, 'visit'])->name('visits.store');
            Route::post('payments', [CollectionController::class, 'payment'])->name('payments.store');
            Route::get('cash-cut', [CollectionController::class, 'cashCut'])->name('cash-cut');
            Route::post('cash-cut/close', [CollectionController::class, 'closeCashCut'])->name('cash-cut.close');
            Route::get('cash-cuts', [CollectionController::class, 'cashCuts'])->name('cash-cuts');
        });
    });

    // ---------------- Administración e integraciones (función "api" del plan) ----------------
    Route::middleware(ApiAuthenticate::class.':staff,api')->group(function () use ($can) {
        Route::post('clients', [ClientController::class, 'store'])->middleware($can('clients.manage'))->name('clients.store');
        Route::match(['put', 'patch'], 'clients/{id}', [ClientController::class, 'update'])->whereNumber('id')->middleware($can('clients.manage'))->name('clients.update');

        Route::middleware($can('billing.view'))->group(function () {
            Route::get('payments', [BillingController::class, 'payments'])->name('payments.index');
            Route::get('charges', [BillingController::class, 'charges'])->name('charges.index');
        });
        Route::post('payments', [BillingController::class, 'storePayment'])->middleware($can('billing.manage'))->name('payments.store');

        Route::prefix('network')->name('network.')->middleware($can('network.view'))->group(function () {
            Route::get('repeaters', [NetworkController::class, 'repeaters'])->name('repeaters.index');
            Route::get('repeaters/{id}', [NetworkController::class, 'repeater'])->whereNumber('id')->name('repeaters.show');
            Route::get('devices', [NetworkController::class, 'devices'])->name('devices.index');
            Route::get('devices/{id}', [NetworkController::class, 'device'])->whereNumber('id')->name('devices.show');
        });

        Route::get('alerts', [NetworkController::class, 'alerts'])->middleware($can('alerts.view'))->name('alerts.index');
        Route::get('metrics/summary', [NetworkController::class, 'metrics'])->middleware($can('dashboard.view'))->name('metrics.summary');

        Route::post('integration/key', [IntegrationController::class, 'generateKey'])->middleware($can('settings.manage'))->name('integration.key');
    });

    // ---------------- App del cliente (sección 33) ----------------
    Route::prefix('client')->name('client.')->middleware(ApiAuthenticate::class.':client,app_cliente|api')->group(function () {
        Route::get('home', [ClientAppController::class, 'home'])->name('home');
        Route::get('status', [ClientAppController::class, 'status'])->name('status');
        Route::get('plan', [ClientAppController::class, 'plan'])->name('plan');
        Route::get('usage', [ClientAppController::class, 'usage'])->name('usage');
        Route::get('payments', [ClientAppController::class, 'payments'])->name('payments');
        Route::get('charges', [ClientAppController::class, 'charges'])->name('charges');
        Route::post('pay', [ClientAppController::class, 'pay'])->name('pay');
        Route::post('report', [ClientAppController::class, 'report'])->name('report');
        Route::get('tickets', [ClientAppController::class, 'tickets'])->name('tickets');
        Route::get('tickets/{id}', [ClientAppController::class, 'ticket'])->whereNumber('id')->name('tickets.show');
        Route::post('tickets/{id}/comments', [ClientAppController::class, 'ticketComment'])->whereNumber('id')->name('tickets.comments');
        Route::get('support', [ClientAppController::class, 'support'])->name('support');
        Route::get('profile', [ClientAppController::class, 'profile'])->name('profile');
        Route::match(['put', 'patch'], 'profile', [ClientAppController::class, 'updateProfile'])->name('profile.update');
        Route::get('promotions', [ClientAppController::class, 'promotions'])->name('promotions');
        Route::get('referrals', [ClientAppController::class, 'referrals'])->name('referrals');
        Route::post('push-token', [PushTokenController::class, 'store'])->name('push-token.store');
        Route::delete('push-token', [PushTokenController::class, 'destroy'])->name('push-token.destroy');
    });

    // ---------------- Integraciones con API key del WISP ----------------
    Route::prefix('integration')->name('integration.')->middleware(ApiKeyAuthenticate::class)->group(function () {
        Route::get('clients', [IntegrationController::class, 'clients'])->name('clients');
        Route::get('clients/{code}', [IntegrationController::class, 'client'])->name('clients.show');
        Route::post('payments', [IntegrationController::class, 'payment'])->name('payments');
    });

    Route::fallback(fn () => response()->json(['message' => 'Endpoint no encontrado. Consulta la documentación en '.url('/api/docs')], 404));
});
