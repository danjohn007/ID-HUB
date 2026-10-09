<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Determina el WISP activo: el del usuario, o el elegido por el superadmin
 * (session 'acting_tenant_id'). Bloquea usuarios inactivos y WISP suspendidos.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Tu usuario está desactivado.']);
        }

        $tenant = null;
        if ($user->isSuperadmin()) {
            if ($id = $request->session()->get('acting_tenant_id')) {
                $tenant = Tenant::find($id);
            }
        } else {
            $tenant = $user->tenant;
            if (! $tenant || ! $tenant->isActive()) {
                Auth::logout();
                $request->session()->invalidate();

                return redirect()->route('login')->withErrors(['email' => 'La cuenta de tu empresa está suspendida. Contacta al administrador.']);
            }
        }

        // Las fechas se guardan en la zona horaria de la app (config/app.php);
        // tenants.timezone se usa para programar cortes y recordatorios.
        app(TenantContext::class)->set($tenant);

        return $next($request);
    }
}
