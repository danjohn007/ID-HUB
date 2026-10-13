<?php

namespace App\Livewire\Maintenances;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Maintenance;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\Network\MaintenanceService;
use App\Support\InputRules;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mantenimiento programado (sección 37): fecha, hora, zona, repetidor, equipos afectados,
 * clientes potencialmente afectados y aviso anticipado (automático o manual).
 */
#[Title('Mantenimientos')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    protected function blank(): array
    {
        $start = now()->addDay()->setTime(2, 0);

        return [
            'title' => '', 'description' => '', 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->copy()->addHours(2)->format('Y-m-d\TH:i'),
            'zone_id' => '', 'repeater_id' => '', 'device_ids' => [], 'notify_hours_before' => '24',
        ];
    }

    public function mount(): void
    {
        $this->form = $this->blank();
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
        $m = Maintenance::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $m->id;
        $this->form = [
            'title' => $m->title, 'description' => (string) $m->description,
            'starts_at' => $m->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $m->ends_at?->format('Y-m-d\TH:i') ?? '',
            'zone_id' => (string) ($m->zone_id ?? ''), 'repeater_id' => (string) ($m->repeater_id ?? ''),
            'device_ids' => array_map('strval', $m->device_ids ?? []), 'notify_hours_before' => (string) $m->notify_hours_before,
        ];
        $this->showForm = true;
    }

    public function updatedFormRepeaterId(): void
    {
        $this->form['device_ids'] = [];
    }

    /** Mantenimiento sin guardar con los datos del formulario (para contar afectados). */
    protected function draft(): Maintenance
    {
        return new Maintenance([
            'zone_id' => $this->form['zone_id'] ?: null,
            'repeater_id' => $this->form['repeater_id'] ?: null,
            'device_ids' => array_map('intval', $this->form['device_ids'] ?? []) ?: null,
        ]);
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.title' => 'required|string|max:150|'.InputRules::TEXT,
            'form.description' => 'nullable|string|max:1000',
            'form.starts_at' => 'required|date',
            'form.ends_at' => 'nullable|date|after:form.starts_at',
            'form.zone_id' => ['nullable', Rule::exists('zones', 'id')->where('tenant_id', tenant()->id)],
            'form.repeater_id' => ['nullable', Rule::exists('repeaters', 'id')->where('tenant_id', tenant()->id)],
            'form.device_ids' => 'array',
            'form.device_ids.*' => [Rule::exists('network_devices', 'id')->where('tenant_id', tenant()->id)],
            'form.notify_hours_before' => 'required|integer|between:0,720',
        ], [], [
            'form.title' => 'título', 'form.description' => 'descripción', 'form.starts_at' => 'inicio', 'form.ends_at' => 'fin', 'form.zone_id' => 'zona',
            'form.repeater_id' => 'repetidor', 'form.notify_hours_before' => 'aviso anticipado',
        ])['form'];

        if (! $data['zone_id'] && ! $data['repeater_id'] && ! $data['device_ids']) {
            $this->addError('form.zone_id', 'Indica al menos una zona, un repetidor o equipos afectados.');

            return;
        }

        $attrs = [
            'title' => $data['title'], 'description' => $data['description'] ?: null,
            'starts_at' => Carbon::parse($data['starts_at']), 'ends_at' => $data['ends_at'] ? Carbon::parse($data['ends_at']) : null,
            'zone_id' => $data['zone_id'] ?: null, 'repeater_id' => $data['repeater_id'] ?: null,
            'device_ids' => array_map('intval', $data['device_ids']) ?: null, 'notify_hours_before' => (int) $data['notify_hours_before'],
        ];
        $m = $this->editingId ? Maintenance::findOrFail($this->editingId) : new Maintenance(['status' => 'scheduled', 'created_by' => auth()->id()]);
        $m->fill($attrs);
        $m->affected_clients = $m->affectedClientsQuery()->count();
        $m->save();

        $this->showForm = false;
        $this->toast($this->editingId ? 'Mantenimiento actualizado' : "Mantenimiento programado ({$m->affected_clients} clientes potencialmente afectados)");
    }

    public function notifyNow(int $id, MaintenanceService $service): void
    {
        $m = Maintenance::whereIn('status', ['scheduled', 'in_progress'])->findOrFail($id);
        $n = $service->notify($m);
        $this->toast("Aviso enviado a {$n} cliente(s)");
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, ['in_progress', 'completed', 'cancelled'], true), 422);
        $m = Maintenance::findOrFail($id);
        $m->update(['status' => $status]);
        $this->toast('Mantenimiento '.mb_strtolower(Maintenance::STATUS[$status][0]), $status === 'cancelled' ? 'warning' : 'success');
    }

    public function delete(int $id): void
    {
        Maintenance::where('status', '!=', 'in_progress')->findOrFail($id)->delete();
        $this->showForm = false;
        $this->toast('Mantenimiento eliminado', 'warning');
    }

    public function render()
    {
        $counts = Maintenance::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.maintenances.index', [
            'maintenances' => Maintenance::query()->with('zone:id,name', 'repeater:id,code,name')
                ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
                ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
                ->orderByRaw("FIELD(status, 'in_progress', 'scheduled', 'completed', 'cancelled')")
                ->orderBy('starts_at', $this->filterStatus === 'completed' ? 'desc' : 'asc')
                ->paginate($this->perPage),
            'counts' => $counts,
            'upcoming' => Maintenance::query()->where('status', 'scheduled')->where('starts_at', '<=', now()->addDays(7))->count(),
            'zones' => Zone::query()->orderBy('name')->pluck('name', 'id')->all(),
            'repeaters' => Repeater::query()->orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn ($r) => [$r->id => "{$r->code} · {$r->name}"])->all(),
            'devices' => $this->showForm
                ? NetworkDevice::query()->where('status', '!=', 'retired')->where('type', '!=', 'cpe')
                    ->when($this->form['repeater_id'] ?? null, fn ($q, $r) => $q->where('repeater_id', $r))
                    ->orderBy('name')->limit(200)->get(['id', 'name', 'type'])
                : collect(),
            'affectedPreview' => $this->showForm ? $this->draft()->affectedClientsQuery()->count() : 0,
        ]);
    }
}
