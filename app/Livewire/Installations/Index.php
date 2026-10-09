<?php

namespace App\Livewire\Installations;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\InstallationOrder;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProspectService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Órdenes de instalación (sección 40): listado y agenda por fecha programada.
 */
#[Title('Instalaciones')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'vista', except: 'agenda')]
    public string $view = 'agenda';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'tecnico', except: '')]
    public string $filterTechnician = '';

    protected array $sortable = ['folio', 'scheduled_at', 'status', 'quote_amount', 'created_at'];

    public bool $showCreate = false;

    public array $form = ['prospect_id' => '', 'plan_id' => '', 'promotion_id' => '', 'technician_id' => '', 'notes' => ''];

    public function setView(string $view): void
    {
        $this->view = $view === 'lista' ? 'lista' : 'agenda';
    }

    public function clearFilters(): void
    {
        $this->reset('filterStatus', 'filterTechnician', 'search');
        $this->resetPage();
    }

    public function updatedForm($value, $key): void
    {
        if ($key === 'prospect_id' && $value && ($p = Prospect::find($value)) && $p->desired_plan_id) {
            $this->form['plan_id'] = (string) $p->desired_plan_id;
        }
    }

    public function create(ProspectService $service)
    {
        $tid = tenant()->id;
        $this->validate([
            'form.prospect_id' => ['required', Rule::exists('prospects', 'id')->where('tenant_id', $tid)],
            'form.plan_id' => ['required', Rule::exists('plans', 'id')->where('tenant_id', $tid)],
            'form.promotion_id' => ['nullable', Rule::exists('promotions', 'id')->where('tenant_id', $tid)],
            'form.technician_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'form.notes' => 'nullable|string|max:1000',
        ], [], ['form.prospect_id' => 'prospecto', 'form.plan_id' => 'plan']);

        $order = $service->createOrder(Prospect::findOrFail($this->form['prospect_id']), array_map(fn ($v) => $v === '' ? null : $v, $this->form));
        session()->flash('success', "Orden {$order->folio} creada");

        return $this->redirectRoute('installations.show', $order, navigate: true);
    }

    protected function query()
    {
        return InstallationOrder::query()
            ->with(['prospect:id,name,address,phone,zone_id', 'client:id,code,first_name,last_name,address,phone', 'plan:id,name,color', 'technician:id,name', 'repeater:id,code'])
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('folio', 'like', $like)
                    ->orWhereHas('prospect', fn ($p) => $p->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('client', fn ($c) => $c->search($this->search)));
            })
            ->when($this->filterStatus, fn ($q) => $this->filterStatus === 'pendientes'
                ? $q->whereIn('status', ['cotizacion', 'aceptada', 'programada', 'en_proceso', 'instalada'])
                : $q->where('status', $this->filterStatus))
            ->when($this->filterTechnician, fn ($q) => $this->filterTechnician === 'none' ? $q->whereNull('technician_id') : $q->where('technician_id', $this->filterTechnician));
    }

    public function render()
    {
        $counts = InstallationOrder::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $data = [
            'counts' => $counts,
            'kpi' => [
                'pending' => collect(['cotizacion', 'aceptada', 'programada', 'en_proceso', 'instalada'])->sum(fn ($s) => $counts[$s] ?? 0),
                'today' => InstallationOrder::query()->whereDate('scheduled_at', today())->whereNotIn('status', ['cancelada', 'activada'])->count(),
                'week' => InstallationOrder::query()->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->whereNotIn('status', ['cancelada', 'activada'])->count(),
                'overdue' => InstallationOrder::query()->where('scheduled_at', '<', now()->startOfDay())->whereIn('status', ['programada', 'aceptada'])->count(),
                'activated30' => InstallationOrder::query()->where('status', 'activada')->where('activated_at', '>=', now()->subDays(30))->count(),
            ],
            'technicians' => User::forTenant()->active()->role(['tecnico', 'supervisor'])->orderBy('name')->pluck('name', 'id'),
        ];

        if ($this->view === 'lista') {
            $data['orders'] = $this->applySort($this->query(), 'created_at', 'desc')->paginate($this->perPage);
        } else {
            // Agenda: atrasadas, próximos 14 días (por día) y sin programar
            $active = fn () => $this->query()->whereNotIn('status', ['cancelada', 'activada']);
            $upcoming = $active()->whereNotNull('scheduled_at')->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDays(14)->endOfDay()])->orderBy('scheduled_at')->get();
            $data['overdue'] = $active()->whereNotNull('scheduled_at')->where('scheduled_at', '<', now()->startOfDay())->orderBy('scheduled_at')->get();
            $data['days'] = collect(range(0, 13))->map(fn ($i) => now()->startOfDay()->addDays($i))
                ->map(fn ($d) => ['date' => $d, 'orders' => $upcoming->filter(fn ($o) => $o->scheduled_at->isSameDay($d))->values()]);
            $data['unscheduled'] = $active()->whereNull('scheduled_at')->latest()->limit(40)->get();
            $data['later'] = $active()->where('scheduled_at', '>', now()->addDays(14)->endOfDay())->count();
        }
        if ($this->showCreate) {
            $data['prospects'] = Prospect::query()->whereIn('status', ['nuevo', 'contactado', 'viable', 'sin_cobertura', 'instalacion_pendiente'])
                ->orderBy('name')->get(['id', 'name', 'has_coverage', 'status'])
                ->mapWithKeys(fn ($p) => [$p->id => $p->name.($p->has_coverage === false ? ' (sin cobertura)' : '')]);
            $data['plans'] = Plan::where('active', true)->orderBy('price')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.money($p->price).' · instalación '.money($p->installation_fee)]);
            $data['promotions'] = Promotion::query()->current()->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.$p->valueLabel()]);
        }

        return view('livewire.installations.index', $data);
    }
}
