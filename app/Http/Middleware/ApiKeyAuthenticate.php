<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integraciones servidor-a-servidor (ERP, contabilidad, CRM, IoT) con una API key por WISP.
 * Encabezado: X-Api-Key: wc_<tenant_id>_<secreto>. Se guarda sólo el hash SHA-256 en
 * tenants.settings.api.key_hash (se genera con POST /api/v1/integration/key).
 */
class ApiKeyAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Api-Key');
        if (! preg_match('/^wc_(\d+)_([A-Za-z0-9]{40})$/', $key, $m)) {
            return ApiAuthenticate::error('Falta el encabezado X-Api-Key o su formato no es válido.', 401);
        }

        $tenant = Tenant::find((int) $m[1]);
        $hash = $tenant ? $tenant->setting('api.key_hash') : null;
        if (! $tenant || ! $hash || ! hash_equals($hash, hash('sha256', $key))) {
            return ApiAuthenticate::error('API key inválida.', 401);
        }
        if (! $tenant->isActive()) {
            return ApiAuthenticate::error('La cuenta de la empresa está suspendida.', 403);
        }
        if (! $tenant->hasFeature('api')) {
            return ApiAuthenticate::error('El plan de la empresa no incluye acceso a la API de integraciones.', 403);
        }

        app(TenantContext::class)->set($tenant);
        $request->attributes->set('api_integration', true);

        return $next($request);
    }
}
