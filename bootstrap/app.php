<?php

use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetTenantContext::class]);
        // El WISP debe fijarse ANTES de resolver modelos de la ruta, para que el
        // scope de tenant aplique al route model binding (aislamiento entre WISP).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: SetTenantContext::class,
        );
        $middleware->alias(['tenant' => RequireTenant::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'captive/*/event', 'mikrotik/push/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route(auth()->user()->homeRoute()));
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// En cPanel la carpeta pública puede ser public_html (hermana de la app). Ver deploy/build-package.sh.
if (is_file(__DIR__.'/public_path.php') && ($publicPath = require __DIR__.'/public_path.php')) {
    $app->usePublicPath($publicPath);
}

return $app;
