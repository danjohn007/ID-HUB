<?php

namespace App\Providers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * API REST v1 (sección 50): limitadores de peticiones y errores JSON en español
 * para todas las rutas /api/*.
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Peticiones autenticadas: por token; anónimas: por IP.
        RateLimiter::for('api', function (Request $request) {
            $key = $request->bearerToken() ? 't:'.sha1($request->bearerToken()) : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('wisp.api.rate_limit', 120))->by($key);
        });

        // Inicio de sesión: protege contra fuerza bruta.
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-id:'.mb_strtolower((string) ($request->input('email') ?? $request->input('identifier'))).'|'.$request->ip()),
        ]);

        $this->registerJsonErrors();
    }

    protected function registerJsonErrors(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            [$status, $message, $extra] = match (true) {
                $e instanceof ValidationException => [422, 'Los datos enviados no son válidos.', ['errors' => $e->errors()]],
                $e instanceof AuthenticationException => [401, 'No autenticado. Envía un token válido en el encabezado Authorization: Bearer.', []],
                $e instanceof InvalidSignatureException => [403, 'El enlace no es válido o ha expirado.', []],
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, self::spanish($e->getMessage(), 'No tienes permiso para realizar esta acción.'), []],
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'Recurso no encontrado.', []],
                $e instanceof MethodNotAllowedHttpException => [405, 'Método HTTP no permitido para esta ruta.', []],
                $e instanceof ThrottleRequestsException => [429, 'Demasiadas solicitudes. Intenta de nuevo en '.($e->getHeaders()['Retry-After'] ?? 60).' segundos.', []],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::spanish($e->getMessage(), 'Error en la solicitud.'), []],
                default => [500, 'Error interno del servidor. Intenta más tarde.', config('app.debug') ? ['debug' => $e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()] : []],
            };

            if ($status >= 500 && ! $e instanceof HttpExceptionInterface) {
                report($e);
            }
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            return response()->json(array_merge(['message' => $message], $extra), $status, $headers,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    }

    /** Conserva mensajes propios (en español) y reemplaza los genéricos del framework. */
    protected static function spanish(?string $message, string $fallback): string
    {
        $generic = ['', 'This action is unauthorized.', 'Forbidden', 'Not Found', 'Unauthenticated.', 'Server Error', 'Too Many Attempts.'];

        return in_array((string) $message, $generic, true) ? $fallback : (string) $message;
    }
}
