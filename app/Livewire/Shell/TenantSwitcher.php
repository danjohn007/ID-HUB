<?php

namespace App\Livewire\Shell;

use App\Models\Tenant;
use Livewire\Component;

/**
 * Selector de WISP en la barra superior. El superadmin puede "entrar" a cualquier
 * WISP para operarlo o volver al panel SaaS.
 */
class TenantSwitcher extends Component
{
    public function switchTo(?int $tenantId = null)
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        if ($tenantId) {
            session(['acting_tenant_id' => $tenantId]);

            return $this->redirectRoute('dashboard', navigate: false);
        }
        session()->forget('acting_tenant_id');

        return $this->redirectRoute('saas.dashboard', navigate: false);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.shell.tenant-switcher', [
            'current' => tenant(),
            'tenants' => $user->isSuperadmin() ? Tenant::orderBy('name')->get(['id', 'name', 'status']) : collect(),
            'isSuperadmin' => $user->isSuperadmin(),
        ]);
    }
}
