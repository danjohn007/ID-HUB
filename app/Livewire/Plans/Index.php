<?php

namespace App\Livewire\Plans;

use App\Livewire\Concerns\Toasts;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Promotion;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Planes de Internet (sección 5): velocidades, precio e IVA, periodicidad, límite de consumo,
 * políticas de suspensión / reconexión, cargos, equipo permitido y perfil MikroTik.
 */
#[Title('Planes de Internet')]
class Index extends Component
{
    use Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public bool $showInactive = true;

    public const COLORS = ['#1A7FF5', '#22A06B', '#6048D0', '#F7881F', '#00A0A8', '#E5484D', '#0B1B3A', '#94A3B8'];

    public function mount(): void
    {
        $this->form = $this->blank();
    }

    protected function blank(): array
    {
        return [
            'name' => '', 'description' => '', 'download_mbps' => '', 'upload_mbps' => '', 'price' => '', 'tax_rate' => '16',
            'price_includes_tax' => true, 'periodicity' => 'monthly', 'data_cap_gb' => '', 'grace_days' => '', 'block_type' => '',
            'late_fee' => '', 'reconnection_fee' => '0', 'installation_fee' => '0', 'auto_reconnect' => true, 'allowed_equipment' => '',
            'mikrotik_profile' => '', 'color' => self::COLORS[0], 'active' => true, 'public' => true,
        ];
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blank();
        $this->form['color'] = self::COLORS[Plan::count() % count(self::COLORS)];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Plan::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $p->id;
        $this->form = array_merge($this->blank(), collect($p->only(array_keys($this->blank())))
            ->map(fn ($v) => is_bool($v) ? $v : (string) ($v ?? ''))->all());
        foreach (['download_mbps', 'upload_mbps', 'price', 'tax_rate', 'late_fee', 'reconnection_fee', 'installation_fee'] as $k) {
            $this->form[$k] = $this->form[$k] === '' ? '' : (string) (float) $this->form[$k];
        }
        $this->showForm = true;
    }

    public function duplicate(int $id): void
    {
        $this->edit($id);
        $this->editingId = null;
        $this->form['name'] .= ' (copia)';
    }

    protected function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:80', Rule::unique('plans', 'name')->where('tenant_id', tenant()->id)->ignore($this->editingId)],
            'form.description' => 'nullable|string|max:190',
            'form.download_mbps' => 'required|numeric|min:0.1|max:100000',
            'form.upload_mbps' => 'required|numeric|min:0.1|max:100000',
            'form.price' => 'required|numeric|min:0|max:1000000',
            'form.tax_rate' => 'required|numeric|between:0,100',
            'form.price_includes_tax' => 'boolean',
            'form.periodicity' => ['required', Rule::in(array_keys(config('wisp.periodicity')))],
            'form.data_cap_gb' => 'nullable|integer|min:1|max:1000000',
            'form.grace_days' => 'nullable|integer|between:0,60',
            'form.block_type' => 'nullable|in:partial,total',
            'form.late_fee' => 'nullable|numeric|min:0|max:100000',
            'form.reconnection_fee' => 'nullable|numeric|min:0|max:100000',
            'form.installation_fee' => 'nullable|numeric|min:0|max:100000',
            'form.auto_reconnect' => 'boolean',
            'form.allowed_equipment' => 'nullable|string|max:190',
            'form.mikrotik_profile' => 'nullable|string|max:80',
            'form.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'form.active' => 'boolean',
            'form.public' => 'boolean',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.name' => 'nombre', 'form.download_mbps' => 'velocidad de descarga', 'form.upload_mbps' => 'velocidad de subida',
            'form.price' => 'precio', 'form.tax_rate' => 'IVA', 'form.data_cap_gb' => 'límite de consumo', 'form.grace_days' => 'días de tolerancia',
            'form.late_fee' => 'recargo', 'form.reconnection_fee' => 'cargo de reconexión', 'form.installation_fee' => 'costo de instalación',
            'form.mikrotik_profile' => 'perfil MikroTik',
        ];
    }

    public function save(): void
    {
        $data = $this->validate()['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['reconnection_fee'] ??= 0;
        $data['installation_fee'] ??= 0;

        if ($this->editingId) {
            $plan = Plan::findOrFail($this->editingId);
            $plan->update($data);
            $this->toast('Plan actualizado');
        } else {
            $plan = Plan::create($data);
            $this->toast('Plan creado');
        }
        $this->showForm = false;
    }

    public function toggle(int $id, string $field): void
    {
        abort_unless(in_array($field, ['active', 'public'], true), 422);
        $p = Plan::findOrFail($id);
        $p->update([$field => ! $p->{$field}]);
        $this->toast($field === 'active' ? ($p->active ? 'Plan activado' : 'Plan desactivado') : ($p->public ? 'Plan visible en portal' : 'Plan oculto del portal'));
    }

    public function delete(int $id): void
    {
        $p = Plan::findOrFail($id);
        $clients = Client::withTrashed()->where('plan_id', $p->id)->count();
        if ($clients > 0) {
            $p->update(['active' => false, 'public' => false]);
            $this->showForm = false;
            $this->toast("El plan tiene {$clients} cliente(s): no se puede eliminar, se desactivó.", 'warning');

            return;
        }
        $p->delete();
        $this->showForm = false;
        $this->toast('Plan eliminado');
    }

    public function render()
    {
        $plans = Plan::query()->when(! $this->showInactive, fn ($q) => $q->where('active', true))->orderByDesc('active')->orderBy('price')->get();
        $stats = Client::query()->selectRaw('plan_id, COUNT(*) total, SUM(CASE WHEN service_status IN (\''.implode("','", Client::BILLABLE).'\') THEN 1 ELSE 0 END) billable, SUM(CASE WHEN service_status = \'activo\' THEN 1 ELSE 0 END) active_n')
            ->groupBy('plan_id')->get()->keyBy('plan_id');
        $rows = $plans->map(function ($p) use ($stats) {
            $s = $stats[$p->id] ?? null;
            $billable = (int) ($s->billable ?? 0);
            $mrr = $billable * (float) $p->price / max(1, $p->periodMonths());

            return ['plan' => $p, 'clients' => (int) ($s->total ?? 0), 'billable' => $billable, 'active' => (int) ($s->active_n ?? 0), 'mrr' => $mrr];
        });
        $totalMrr = $rows->sum('mrr');
        $promos = Promotion::query()->current()->get();

        return view('livewire.plans.index', [
            'rows' => $rows,
            'totalMrr' => $totalMrr,
            'totalClients' => $rows->sum('billable'),
            'promosByPlan' => fn ($planId) => $promos->filter(fn ($pr) => empty($pr->plan_ids) || in_array($planId, (array) $pr->plan_ids)),
            'colors' => self::COLORS,
        ]);
    }
}
