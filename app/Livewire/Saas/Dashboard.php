<?php

namespace App\Livewire\Saas;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Concerns\PlatformContext;
use App\Models\SaasPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SaasBillingService;
use App\Services\TenantProvisioner;
use App\Support\InputRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Panel del superadministrador SaaS (sección 3): métricas generales,
 * alta de WISP con su administrador, suspensión/reactivación y acceso a operar cada WISP.
 */
#[Title('Panel SaaS')]
class Dashboard extends Component
{
    use PlatformContext, Toasts;

    public string $search = '';

    public bool $showForm = false;

    public array $form = [];

    public bool $showEdit = false;

    public ?int $editingId = null;

    public array $edit = [];

    public const STATUSES = ['active' => 'Activo', 'trial' => 'Prueba', 'suspended' => 'Suspendido', 'cancelled' => 'Cancelado'];

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
    }

    public function editTenant(int $id): void
    {
        $t = Tenant::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $t->id;
        $this->edit = [
            'name' => $t->name, 'email' => (string) $t->email, 'phone' => (string) $t->phone, 'saas_plan_id' => (string) ($t->saas_plan_id ?? ''),
            'status' => $t->status, 'trial_ends_at' => $t->trial_ends_at?->toDateString() ?? '',
            'subscription_ends_at' => $t->subscription_ends_at?->toDateString() ?? '', 'suspended_reason' => (string) $t->suspended_reason,
        ];
        $this->showEdit = true;
    }

    public function updateTenant(): void
    {
        $t = Tenant::findOrFail($this->editingId);
        $data = $this->validate([
            'edit.name' => 'required|string|max:120|'.InputRules::TEXT,
            'edit.email' => 'nullable|email|max:150',
            'edit.phone' => 'nullable|string|max:20|'.InputRules::PHONE,
            'edit.saas_plan_id' => 'nullable|exists:saas_plans,id',
            'edit.status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'edit.trial_ends_at' => 'nullable|date',
            'edit.subscription_ends_at' => 'nullable|date',
            'edit.suspended_reason' => 'nullable|string|max:190',
        ], [], [
            'edit.name' => 'nombre', 'edit.email' => 'correo', 'edit.phone' => 'teléfono', 'edit.saas_plan_id' => 'plan', 'edit.status' => 'estado',
            'edit.trial_ends_at' => 'fin de prueba', 'edit.subscription_ends_at' => 'vigencia', 'edit.suspended_reason' => 'motivo',
        ])['edit'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if ($data['status'] !== 'suspended') {
            $data['suspended_reason'] = null;
        }
        $this->withTenant($t, fn () => $t->update($data)); // auditado por el modelo con valores anterior/nuevo
        $this->showEdit = false;
        $this->toast("WISP {$t->name} actualizado");
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->form = [
            'name' => '', 'slug' => '', 'email' => '', 'phone' => '', 'saas_plan_id' => SaasPlan::where('active', true)->orderBy('sort')->value('id'),
            'admin_name' => '', 'admin_email' => '', 'admin_password' => '',
        ];
        $this->showForm = true;
    }

    public function updatedFormName(string $value): void
    {
        if (blank($this->form['slug'] ?? '')) {
            $this->form['slug'] = Str::slug($value);
        }
    }

    public function save(TenantProvisioner $provisioner): void
    {
        $data = $this->validate([
            'form.name' => 'required|string|max:120|'.InputRules::TEXT,
            'form.slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('tenants', 'slug')],
            'form.email' => 'nullable|email|max:150',
            'form.phone' => 'nullable|string|max:20|'.InputRules::PHONE,
            'form.saas_plan_id' => 'nullable|exists:saas_plans,id',
            'form.admin_name' => 'required|string|max:80|'.InputRules::NAME,
            'form.admin_email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'form.admin_password' => ['required', 'string', 'max:255', Password::min(8)->letters()->numbers()],
        ], [], [
            'form.name' => 'nombre', 'form.slug' => 'identificador', 'form.email' => 'correo', 'form.phone' => 'teléfono',
            'form.saas_plan_id' => 'plan', 'form.admin_name' => 'nombre del administrador',
            'form.admin_email' => 'correo del administrador', 'form.admin_password' => 'contraseña',
        ])['form'];

        $tenant = DB::transaction(function () use ($data, $provisioner) {
            $tenant = Tenant::create([
                'name' => $data['name'], 'slug' => $data['slug'], 'subdomain' => $data['slug'],
                'email' => $data['email'] ?: null, 'phone' => $data['phone'] ?: null, 'whatsapp' => $data['phone'] ?: null,
                'saas_plan_id' => $data['saas_plan_id'] ?: null, 'status' => 'active',
                'subscription_ends_at' => now()->addMonth()->startOfMonth(),
            ]);
            $this->withTenant($tenant, function () use ($tenant, $data, $provisioner) {
                $provisioner->provision($tenant);
                User::create([
                    'tenant_id' => $tenant->id, 'name' => $data['admin_name'], 'email' => $data['admin_email'],
                    'password' => $data['admin_password'], 'role' => 'admin', 'active' => true,
                ]);
            });

            return $tenant;
        });

        $this->platformLog('tenant_created', "creó el WISP {$tenant->name}", $tenant, $tenant);
        $this->showForm = false;
        $this->toast("WISP {$tenant->name} creado. Su administrador ya puede iniciar sesión.");
    }

    public function toggleStatus(int $id): void
    {
        $tenant = Tenant::findOrFail($id);
        $suspend = $tenant->isActive();
        $old = ['status' => $tenant->status];
        $tenant->update($suspend
            ? ['status' => 'suspended', 'suspended_reason' => 'Suspendido desde el panel SaaS']
            : ['status' => 'active', 'suspended_reason' => null]);
        $this->platformLog($suspend ? 'tenant_suspended' : 'tenant_activated', ($suspend ? 'suspendió' : 'reactivó')." el WISP {$tenant->name}", $tenant, $tenant, $old, ['status' => $tenant->status]);
        $this->toast($suspend ? "WISP {$tenant->name} suspendido." : "WISP {$tenant->name} reactivado.", $suspend ? 'warning' : 'success');
    }

    public function render(SaasBillingService $billing)
    {
        $count = fn (string $table, array $where = []) => DB::table($table)->whereNull('deleted_at')->where($where)->selectRaw('tenant_id, COUNT(*) n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $clients = $count('clients');
        $activeClients = $count('clients', ['service_status' => 'activo']);
        $devices = $count('network_devices');
        $online = $count('network_devices', ['status' => 'online']);
        $payments = $billing->onlinePayments(now()->startOfMonth(), now());

        $tenants = Tenant::with('saasPlan')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")))
            ->orderBy('name')->get();

        $all = Tenant::with('saasPlan')->get();

        return view('livewire.saas.dashboard', [
            'tenants' => $tenants,
            'plans' => SaasPlan::orderBy('sort')->pluck('name', 'id')->all(),
            'stats' => compact('clients', 'activeClients', 'devices', 'online', 'payments'),
            'kpi' => [
                'tenants' => $all->count(),
                'tenants_active' => $all->filter->isActive()->count(),
                'clients' => $clients->sum(),
                'clients_active' => $activeClients->sum(),
                'devices' => $devices->sum(),
                'online' => $online->sum(),
                'commissions' => (float) $payments->sum('platform_fee'),
                'mrr' => (float) $all->filter->isActive()->sum(fn ($t) => $t->saasPlan?->price_monthly ?? 0),
            ],
        ]);
    }
}
