<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas de operación WISP: requieren un tenant activo. El superadmin sin WISP
 * seleccionado es enviado al panel SaaS.
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenant()) {
            if ($request->user()?->isSuperadmin()) {
                return redirect()->route('saas.dashboard')->with('warning', 'Selecciona un WISP para operar.');
            }
            abort(403);
        }

        return $next($request);
    }
}
