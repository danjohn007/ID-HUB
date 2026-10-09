<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Contexto del WISP activo. Los modelos con BelongsToTenant filtran por este tenant.
 * Sin tenant (consola / superadmin global) no se aplica filtro.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        if ($tenant) {
            config(['app.timezone_tenant' => $tenant->timezone]);
        }
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    /** Ejecuta un callback dentro del contexto de un tenant y restaura el anterior. */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);
        try {
            return $callback($tenant);
        } finally {
            $this->set($previous);
        }
    }
}
