<?php

namespace App\Livewire\Automations;

use App\Livewire\Concerns\Toasts;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Services\Automation\RuleCatalog;
use App\Support\Audit;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Motor de reglas Evento → Condición → Acción (sección 46): reglas como cadenas visuales,
 * activación, plantillas de ejemplo e historial de ejecuciones.
 */
#[Title('Automatizaciones')]
class Index extends Component
{
    use Toasts, WithPagination;

    #[Url(as: 'vista', except: 'reglas')]
    public string $tab = 'reglas';

    #[Url(as: 'evento', except: '')]
    public string $filterEvent = '';

    #[Url(as: 'regla', except: '')]
    public string $filterRule = '';

    #[Url(as: 'resultado', except: '')]
    public string $filterRunStatus = '';

    public function updated($property): void
    {
        if (in_array($property, ['filterRule', 'filterRunStatus', 'tab'])) {
            $this->resetPage();
        }
    }

    public function toggle(int $id): void
    {
        $rule = AutomationRule::findOrFail($id);
        $rule->update(['active' => ! $rule->active]);
        $this->toast($rule->active ? "Regla \"{$rule->name}\" activada" : "Regla \"{$rule->name}\" desactivada", $rule->active ? 'success' : 'warning');
    }

    public function createFromTemplate(string $key)
    {
        $t = RuleCatalog::templates()[$key] ?? null;
        if (! $t) {
            return null;
        }
        $rule = AutomationRule::create($t + ['active' => true, 'system' => false]);
        session()->flash('success', 'Regla creada desde la plantilla. Revísala y pruébala.');

        return $this->redirectRoute('automations.edit', $rule, navigate: true);
    }

    public function duplicate(int $id)
    {
        $r = AutomationRule::findOrFail($id);
        $copy = AutomationRule::create([
            'name' => $r->name.' (copia)', 'description' => $r->description, 'event' => $r->event,
            'conditions' => $r->conditions, 'actions' => $r->actions, 'active' => false, 'system' => false,
        ]);

        return $this->redirectRoute('automations.edit', $copy, navigate: true);
    }

    public function delete(int $id): void
    {
        $r = AutomationRule::findOrFail($id);
        if ($r->system) {
            $this->toast('Las reglas del sistema no se pueden eliminar; puedes desactivarlas', 'warning');

            return;
        }
        Audit::log('deleted', "eliminó la automatización {$r->name}", $r);
        $r->delete();
        $this->toast('Regla eliminada');
    }

    public function showRuns(int $ruleId): void
    {
        $this->filterRule = (string) $ruleId;
        $this->tab = 'historial';
        $this->resetPage();
    }

    /** Etiquetas de sujetos de una página de ejecuciones (una consulta por tipo). */
    protected function subjectLabels($runs): array
    {
        $labels = [];
        foreach ($runs->groupBy('subject_type') as $type => $items) {
            $ids = $items->pluck('subject_id')->filter()->unique()->all();
            $models = match ($type) {
                'client' => Client::withTrashed()->whereIn('id', $ids)->get(),
                'repeater' => Repeater::whereIn('id', $ids)->get(),
                'ticket' => Ticket::whereIn('id', $ids)->get(),
                'device' => NetworkDevice::whereIn('id', $ids)->get(),
                'inventory_item' => InventoryItem::whereIn('id', $ids)->get(),
                default => collect(),
            };
            foreach ($models as $m) {
                $labels[$type.':'.$m->id] = RuleCatalog::describeSubject($m);
            }
        }

        return $labels;
    }

    public function render()
    {
        $rules = AutomationRule::query()
            ->when($this->filterEvent, fn ($q) => $q->where('event', $this->filterEvent))
            ->orderByDesc('system')->orderBy('event')->orderBy('name')->get();

        $since = now()->subDays(30);
        $runStats = AutomationRun::query()->where('created_at', '>=', $since)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $runsByRule = AutomationRun::query()->where('created_at', '>=', $since)->selectRaw('automation_rule_id, COUNT(*) n')->groupBy('automation_rule_id')->pluck('n', 'automation_rule_id');

        $runs = null;
        $labels = [];
        if ($this->tab === 'historial') {
            $runs = AutomationRun::query()->with('rule:id,name,event')
                ->when($this->filterRule, fn ($q) => $q->where('automation_rule_id', $this->filterRule))
                ->when($this->filterRunStatus, fn ($q) => $q->where('status', $this->filterRunStatus))
                ->latest('id')->paginate(25);
            $labels = $this->subjectLabels($runs->getCollection());
        }

        return view('livewire.automations.index', [
            'rules' => $rules,
            'runs' => $runs,
            'labels' => $labels,
            'runsByRule' => $runsByRule,
            'kpi' => [
                'active' => $rules->where('active', true)->count(),
                'total' => $rules->count(),
                'runs' => (int) $runStats->sum(),
                'success' => (int) ($runStats['success'] ?? 0),
                'skipped' => (int) ($runStats['skipped'] ?? 0),
                'failed' => (int) ($runStats['failed'] ?? 0),
            ],
            'templates' => RuleCatalog::templates(),
            'ruleOptions' => AutomationRule::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
