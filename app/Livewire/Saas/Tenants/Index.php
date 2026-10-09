<?php

namespace App\Livewire\Saas\Tenants;

use App\Livewire\Saas\Concerns\PlatformContext;
use App\Livewire\Saas\Dashboard;
use App\Models\SaasInvoice;
use App\Models\SaasPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Empresas WISP (sección 3): uso de cada WISP contra los límites de su plan SaaS
 * (clientes, equipos MikroTik, usuarios), vigencia de la suscripción y adeudo.
 * Alta, edición y suspensión siguen en el Panel SaaS.
 */
#[Title('Empresas WISP')]
class Index extends Component
{
    use PlatformContext;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'plan', except: '')]
    public string $filterPlan = '';

    /** Solo WISP que alcanzaron o rebasan algún límite. */
    #[Url(as: 'limite', except: false)]
    public bool $onlyAtLimit = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
    }

    public function render()
    {
        $usage = collect(array_keys(Tenant::LIMIT_RESOURCES))->mapWithKeys(fn ($r) => [$r => Tenant::usageByTenant($r)]);
        $online = DB::table('network_devices')->whereNull('deleted_at')->where('status', 'online')
            ->selectRaw('tenant_id, COUNT(*) n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $devices = DB::table('network_devices')->whereNull('deleted_at')
            ->selectRaw('tenant_id, COUNT(*) n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $debt = SaasInvoice::query()->whereIn('status', ['pending', 'overdue'])
            ->selectRaw('tenant_id, SUM(total) total, COUNT(*) n')->groupBy('tenant_id')->get()->keyBy('tenant_id');

        $all = Tenant::with('saasPlan')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('slug', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterPlan === 'none', fn ($q) => $q->whereNull('saas_plan_id'))
            ->when($this->filterPlan && $this->filterPlan !== 'none', fn ($q) => $q->where('saas_plan_id', $this->filterPlan))
            ->orderBy('name')->get()
            ->map(function (Tenant $t) use ($usage) {
                $limits = collect(Tenant::LIMIT_RESOURCES)->map(function ($def, $resource) use ($t, $usage) {
                    $used = (int) ($usage[$resource][$t->id] ?? 0);
                    $max = $t->planLimit($resource);

                    return ['label' => ucfirst($def[1]), 'used' => $used, 'max' => $max, 'pct' => $max ? $used / $max * 100 : null];
                });

                return (object) ['tenant' => $t, 'limits' => $limits, 'atLimit' => $limits->contains(fn ($l) => $l['max'] && $l['used'] >= $l['max'])];
            });

        return view('livewire.saas.tenants.index', [
            'rows' => $this->onlyAtLimit ? $all->where('atLimit', true) : $all,
            'online' => $online,
            'devices' => $devices,
            'debt' => $debt,
            'statuses' => Dashboard::STATUSES,
            'plans' => ['none' => 'Sin plan'] + SaasPlan::orderBy('sort')->pluck('name', 'id')->all(),
            'atLimitCount' => $all->where('atLimit', true)->count(),
        ]);
    }
}
