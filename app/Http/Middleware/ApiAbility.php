<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica un permiso de config/permissions.php para el usuario del token
 * (rol del usuario + habilidades con que se emitió el token).
 *
 * Uso: ApiAbility::class.':clients.view'   o varios alternativos: ':tickets.manage|field.work'
 */
class ApiAbility
{
    public function handle(Request $request, Closure $next, string $abilities): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return ApiAuthenticate::error('No autenticado.', 401);
        }

        foreach (explode('|', $abilities) as $ability) {
            if (self::allows($user, $ability)) {
                return $next($request);
            }
        }

        return ApiAuthenticate::error('No tienes permiso para realizar esta acción ('.str_replace('|', ' o ', $abilities).').', 403);
    }

    public static function allows(?User $user, string $ability): bool
    {
        if (! $user || ! $user->hasAbility($ability)) {
            return false;
        }
        $token = $user->currentAccessToken();

        return ! $token || ! method_exists($token, 'can') || $token->can($ability);
    }
}
