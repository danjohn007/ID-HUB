<?php

namespace App\Livewire\Saas\Sales\Concerns;

use App\Models\DemoRequest;
use App\Models\User;
use App\Services\DemoScheduler;

/** Utilidades compartidas por las pantallas del CRM de ventas (superadmin). */
trait SalesContext
{
    /** Usuarios de plataforma que pueden atender prospectos. */
    protected function salesUsers(): array
    {
        return User::whereNull('tenant_id')->where('role', 'superadmin')->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Contadores del menú lateral (fuera de los componentes). */
    protected function refreshBadge(): void
    {
        $this->dispatch('nav-badge', key: 'demo_requests', count: DemoRequest::pendingCount());
        $this->dispatch('nav-badge', key: 'demos_today', count: DemoScheduler::todayCount());
    }
}
