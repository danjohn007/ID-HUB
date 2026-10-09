<?php

namespace App\Livewire\Saas;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Concerns\PlatformContext;
use App\Models\SaasPlan;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Planes de suscripción SaaS (sección 48): límites de clientes / equipos / usuarios,
 * funciones incluidas y comisión de la plataforma por pagos en línea (sección 49).
 */
#[Title('Planes SaaS')]
class Plans extends Component
{
    use PlatformContext, Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        $this->form = $this->blank();
    }

    protected function blank(): array
    {
        return [
            'code' => '', 'name' => '', 'description' => '', 'price_monthly' => '', 'max_clients' => '', 'max_devices' => '', 'max_users' => '',
            'commission_percent' => '0', 'commission_fixed' => '0', 'features' => [], 'active' => true, 'sort' => (string) (SaasPlan::max('sort') + 1),
        ];
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blank();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = SaasPlan::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $p->id;
        $this->form = [
            'code' => $p->code, 'name' => $p->name, 'description' => (string) $p->description, 'price_monthly' => (string) (float) $p->price_monthly,
            'max_clients' => (string) ($p->max_clients ?? ''), 'max_devices' => (string) ($p->max_devices ?? ''), 'max_users' => (string) ($p->max_users ?? ''),
            'commission_percent' => (string) (float) $p->commission_percent, 'commission_fixed' => (string) (float) $p->commission_fixed,
            'features' => array_values($p->features ?? []), 'active' => (bool) $p->active, 'sort' => (string) $p->sort,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.code' => ['required', 'alpha_dash', 'max:40', Rule::unique('saas_plans', 'code')->ignore($this->editingId)],
            'form.name' => 'required|string|max:80',
            'form.description' => 'nullable|string|max:190',
            'form.price_monthly' => 'required|numeric|min:0|max:1000000',
            'form.max_clients' => 'nullable|integer|min:1',
            'form.max_devices' => 'nullable|integer|min:1',
            'form.max_users' => 'nullable|integer|min:1',
            'form.commission_percent' => 'required|numeric|between:0,100',
            'form.commission_fixed' => 'required|numeric|min:0|max:100000',
            'form.features' => 'array',
            'form.features.*' => [Rule::in(array_merge(array_keys(SaasPlan::FEATURES), ['*']))],
            'form.active' => 'boolean',
            'form.sort' => 'nullable|integer|min:0|max:1000',
        ], [], [
            'form.code' => 'código', 'form.name' => 'nombre', 'form.price_monthly' => 'mensualidad', 'form.max_clients' => 'máximo de clientes',
            'form.max_devices' => 'máximo de equipos', 'form.max_users' => 'máximo de usuarios', 'form.commission_percent' => 'comisión %',
            'form.commission_fixed' => 'comisión fija',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['code'] = strtoupper($data['code']);
        $data['features'] = array_values($data['features'] ?? []);
        $data['sort'] = (int) ($data['sort'] ?? 0);

        $plan = $this->editingId ? SaasPlan::findOrFail($this->editingId) : new SaasPlan;
        $plan->fill($data)->save();
        $this->platformLog($this->editingId ? 'updated' : 'created', ($this->editingId ? 'actualizó' : 'creó')." el plan SaaS {$plan->name}", $plan);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Plan actualizado' : 'Plan creado');
    }

    public function toggleActive(int $id): void
    {
        $p = SaasPlan::findOrFail($id);
        $p->update(['active' => ! $p->active]);
        $this->toast($p->active ? 'Plan activado' : 'Plan desactivado: ya no se ofrece a WISP nuevos');
    }

    public function render()
    {
        return view('livewire.saas.plans', [
            'plans' => SaasPlan::query()->withCount('tenants')->orderBy('sort')->orderBy('price_monthly')->get(),
            'features' => SaasPlan::FEATURES,
        ]);
    }
}
