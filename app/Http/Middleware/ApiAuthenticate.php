<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación de la API v1 con tokens Sanctum (Authorization: Bearer).
 *
 * Uso en rutas:  ApiAuthenticate::class.':staff,api|app_tecnico'
 *   1er parámetro: staff | client | any  (tipo de dueño del token)
 *   2º parámetro (opcional): funciones del plan SaaS aceptadas, separadas por "|" (basta con una).
 *
 * - Establece el TenantContext con el WISP del usuario / cliente (aislamiento multi-tenant).
 * - Bloquea WISP suspendidos, usuarios inactivos y tokens vencidos.
 * - Staff: el usuario queda autenticado (auth()->id() funciona en los servicios).
 * - Cliente: NO se autentica en el guard (para que auth()->id() no confunda un cliente con un usuario);
 *   se obtiene con $request->attributes->get('api_client').
 */
class ApiAuthenticate
{
    public function handle(Request $request, Closure $next, string $type = 'any', ?string $features = null): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? PersonalAccessToken::findToken($plain) : null;
        if (! $token) {
            return self::error('No autenticado. Envía un token válido en el encabezado Authorization: Bearer.', 401);
        }

        $expiration = config('sanctum.expiration');
        if (($token->expires_at && $token->expires_at->isPast())
            || ($expiration && $token->created_at && $token->created_at->lte(now()->subMinutes($expiration)))) {
            return self::error('La sesión expiró. Inicia sesión nuevamente.', 401);
        }

        $owner = $token->tokenable;
        if (! $owner) {
            return self::error('No autenticado. El token ya no es válido.', 401);
        }

        $isStaff = $owner instanceof User;
        $isClient = $owner instanceof Client;
        if (($type === 'staff' && ! $isStaff) || ($type === 'client' && ! $isClient)) {
            return self::error($type === 'staff'
                ? 'Este endpoint es exclusivo para personal del WISP.'
                : 'Este endpoint es exclusivo para la app del cliente.', 403);
        }

        $tenant = null;
        if ($isStaff) {
            if (! $owner->active) {
                return self::error('Tu usuario está desactivado.', 403);
            }
            if ($owner->isSuperadmin()) {
                $id = $request->header('X-Tenant-Id');
                $tenant = $id ? Tenant::find($id) : null;
                if (! $tenant && $type !== 'any') {
                    return self::error('Como superadministrador indica el WISP con el encabezado X-Tenant-Id.', 422);
                }
            } else {
                $tenant = $owner->tenant;
            }
        } else {
            $tenant = Tenant::find($owner->tenant_id);
        }

        if ($tenant && ! $tenant->isActive()) {
            return self::error('La cuenta de la empresa está suspendida. Contacta al administrador.', 403);
        }
        if (! $tenant && ! ($isStaff && $owner->isSuperadmin())) {
            return self::error('No se encontró la empresa asociada al token.', 403);
        }

        if ($features && $tenant) {
            $accepted = array_filter(explode('|', $features));
            $ok = collect($accepted)->contains(fn ($f) => $tenant->hasFeature($f));
            if (! $ok) {
                return self::error('El plan de tu empresa no incluye acceso a esta función de la API ('.implode(' / ', $accepted).').', 403);
            }
        }

        app(TenantContext::class)->set($tenant);

        $owner->withAccessToken($token);
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        if ($isStaff) {
            Auth::setUser($owner);
            $request->setUserResolver(fn () => $owner);
        } else {
            $request->attributes->set('api_client', $owner);
        }
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    public static function error(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
