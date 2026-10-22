<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();
        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

            return;
        }
        // Sin WISP activo y con un usuario de WISP en sesión es un error de contexto: no se muestra
        // nada en lugar de mostrar datos de todos los WISP. La consola, las rutas públicas y el
        // superadmin siguen sin filtro (usan su propio contexto o withoutGlobalScopes).
        $user = Auth::hasUser() ? Auth::user() : null;
        if ($user && method_exists($user, 'isSuperadmin') && ! $user->isSuperadmin() && ! app()->runningInConsole()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
