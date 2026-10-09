<?php

namespace App\Livewire\Growth;

use App\Livewire\Concerns\Toasts;
use App\Models\InstallationOrder;
use App\Models\Plan;
use App\Models\Promotion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Promociones (sección 5 y 28): tipo, valor, meses, planes, código, vigencia,
 * visibilidad en portal cautivo, imagen y usos.
 */
#[Title('Promociones')]
class Promotions extends Component
{
    use Toasts, WithFileUploads;

    #[Url(as: 'estado', except: '')]
    public string $filterState = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public $image;

    public function mount(): void
    {
        $this->form = $this->blank();
    }

    protected function blank(): array
    {
        return [
            'name' => '', 'description' => '', 'type' => 'descuento_pct', 'value' => '', 'months' => '1', 'plan_ids' => [],
            'code' => '', 'starts_at' => now()->toDateString(), 'ends_at' => now()->addMonth()->toDateString(),
            'show_in_portal' => true, 'active' => true, 'uses' => '0',
        ];
    }

    public static function state(Promotion $p): string
    {
        $today = now()->startOfDay();

        return match (true) {
            ! $p->active => 'inactiva',
            $p->starts_at && $p->starts_at->gt($today) => 'programada',
            $p->ends_at && $p->ends_at->lt($today) => 'vencida',
            default => 'vigente',
        };
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('image', 'editingId');
        $this->form = $this->blank();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Promotion::findOrFail($id);
        $this->resetValidation();
        $this->reset('image');
        $this->editingId = $p->id;
        $this->form = [
            'name' => $p->name, 'description' => (string) $p->description, 'type' => $p->type, 'value' => (string) (float) $p->value,
            'months' => (string) $p->months, 'plan_ids' => array_map('strval', (array) ($p->plan_ids ?? [])), 'code' => (string) $p->code,
            'starts_at' => $p->starts_at?->toDateString() ?? '', 'ends_at' => $p->ends_at?->toDateString() ?? '',
            'show_in_portal' => (bool) $p->show_in_portal, 'active' => (bool) $p->active, 'uses' => (string) $p->uses,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $tid = tenant()->id;
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.description' => 'nullable|string|max:190',
            'form.type' => ['required', Rule::in(array_keys(config('wisp.promotion_types')))],
            'form.value' => [Rule::requiredIf(in_array($this->form['type'], ['descuento_pct', 'descuento_monto'], true)), 'nullable', 'numeric', 'min:0',
                $this->form['type'] === 'descuento_pct' ? 'max:100' : 'max:100000'],
            'form.months' => 'required|integer|between:1,24',
            'form.plan_ids' => 'array',
            'form.plan_ids.*' => [Rule::exists('plans', 'id')->where('tenant_id', $tid)],
            'form.code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('promotions', 'code')->where('tenant_id', $tid)->ignore($this->editingId)],
            'form.starts_at' => 'nullable|date',
            'form.ends_at' => 'nullable|date|after_or_equal:form.starts_at',
            'form.show_in_portal' => 'boolean',
            'form.active' => 'boolean',
            'form.uses' => 'required|integer|min:0',
            'image' => 'nullable|image|max:4096',
        ], [], [
            'form.name' => 'nombre', 'form.type' => 'tipo', 'form.value' => 'valor', 'form.months' => 'meses', 'form.code' => 'código',
            'form.starts_at' => 'inicio', 'form.ends_at' => 'fin', 'form.uses' => 'usos', 'image' => 'imagen',
        ])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['value'] = (float) ($data['value'] ?? 0);
        $data['code'] = $data['code'] ? strtoupper($data['code']) : null;
        $data['plan_ids'] = $data['plan_ids'] ? array_map('intval', $data['plan_ids']) : null;

        $promo = $this->editingId ? Promotion::findOrFail($this->editingId) : new Promotion;
        if ($this->image) {
            if ($promo->image_path) {
                Storage::disk('public')->delete($promo->image_path);
            }
            $data['image_path'] = $this->image->store('promotions/'.$tid, 'public');
        }
        $promo->fill($data)->save();

        $this->showForm = false;
        $this->reset('image');
        $this->toast($this->editingId ? 'Promoción actualizada' : 'Promoción creada');
    }

    public function removeImage(): void
    {
        if ($this->editingId && ($p = Promotion::find($this->editingId)) && $p->image_path) {
            Storage::disk('public')->delete($p->image_path);
            $p->update(['image_path' => null]);
        }
        $this->reset('image');
    }

    public function toggle(int $id, string $field): void
    {
        abort_unless(in_array($field, ['active', 'show_in_portal'], true), 422);
        $p = Promotion::findOrFail($id);
        $p->update([$field => ! $p->{$field}]);
        $this->toast('Promoción actualizada');
    }

    public function delete(int $id): void
    {
        $p = Promotion::findOrFail($id);
        if (InstallationOrder::where('promotion_id', $p->id)->whereNotIn('status', ['activada', 'cancelada'])->exists()) {
            $p->update(['active' => false]);
            $this->showForm = false;
            $this->toast('La promoción está en órdenes en curso: se desactivó en lugar de eliminarse.', 'warning');

            return;
        }
        if ($p->image_path) {
            Storage::disk('public')->delete($p->image_path);
        }
        $p->delete();
        $this->showForm = false;
        $this->toast('Promoción eliminada');
    }

    public function render()
    {
        $all = Promotion::query()->orderByDesc('active')->latest('starts_at')->get();
        $promos = $this->filterState ? $all->filter(fn ($p) => self::state($p) === $this->filterState)->values() : $all;
        $current = $all->filter(fn ($p) => self::state($p) === 'vigente');

        return view('livewire.growth.promotions', [
            'promotions' => $promos,
            'stateCounts' => $all->countBy(fn ($p) => self::state($p)),
            'kpi' => [
                'current' => $current->count(),
                'portal' => $current->where('show_in_portal', true)->count(),
                'uses' => (int) $all->sum('uses'),
                'expiring' => $current->filter(fn ($p) => $p->ends_at && $p->ends_at->lte(now()->addDays(7)))->count(),
            ],
            'plans' => Plan::orderBy('price')->get(['id', 'name', 'color', 'price']),
            'states' => ['vigente' => ['Vigente', 'success'], 'programada' => ['Programada', 'info'], 'vencida' => ['Vencida', 'slate'], 'inactiva' => ['Inactiva', 'slate']],
        ]);
    }
}
