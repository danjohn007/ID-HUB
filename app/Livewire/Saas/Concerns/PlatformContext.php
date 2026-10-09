<?php

namespace App\Livewire\Saas\Concerns;

use App\Models\Tenant;
use App\Support\Audit;
use App\Support\TenantContext;

/**
 * Utilidades del panel SaaS: el superadmin puede tener un WISP "activo" en sesión,
 * por lo que las acciones de plataforma se ejecutan con el contexto explícito del
 * WISP afectado (o sin WISP) para que auditoría y scopes no se mezclen.
 */
trait PlatformContext
{
    protected function withTenant(?Tenant $tenant, callable $callback): mixed
    {
        $ctx = app(TenantContext::class);
        $previous = $ctx->get();
        $ctx->set($tenant);
        try {
            return $callback();
        } finally {
            $ctx->set($previous);
        }
    }

    protected function platformLog(string $action, string $description, $subject = null, ?Tenant $tenant = null, array $old = [], array $new = []): void
    {
        $this->withTenant($tenant, fn () => Audit::log($action, $description, $subject, $old, $new));
    }

    /** Entrar a operar un WISP (misma lógica que el selector de la barra superior). */
    public function enter(int $tenantId)
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        $tenant = Tenant::findOrFail($tenantId);
        session(['acting_tenant_id' => $tenant->id]);

        return $this->redirectRoute('dashboard', navigate: false);
    }
}
