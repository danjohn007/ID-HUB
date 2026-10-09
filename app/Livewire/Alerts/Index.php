<?php

namespace App\Livewire\Alerts;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Alert;
use App\Services\Alerts\SmartAlertsService;
use App\Services\Automation\RuleCatalog;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Alertas inteligentes (sección 45): filtros por tipo, severidad, estado y fecha; KPIs por
 * severidad; atender, resolver y resolver en lote; tendencia de 14 días.
 */
#[Title('Alertas')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'tipo', except: '')]
    public string $filterType = '';

    #[Url(as: 'severidad', except: '')]
    public string $filterSeverity = '';

    /** open = abiertas + atendidas; all = todas */
    #[Url(as: 'estado', except: 'open')]
    public string $filterStatus = 'open';

    #[Url(as: 'desde', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'hasta', except: '')]
    public string $filterTo = '';

    #[Url(as: 'agrupar', except: false)]
    public bool $grouped = false;

    public array $selected = [];

    protected array $sortable = ['created_at', 'severity', 'type'];

    public function clearFilters(): void
    {
        $this->reset('filterType', 'filterSeverity', 'filterFrom', 'filterTo', 'search');
        $this->filterStatus = 'open';
        $this->resetPage();
    }

    protected function query()
    {
        $term = trim($this->search);

        return Alert::query()->with('acknowledgedBy:id,name')
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterSeverity, fn ($q) => $q->where('severity', $this->filterSeverity))
            ->when($this->filterStatus === 'open', fn ($q) => $q->open())
            ->when(in_array($this->filterStatus, ['acknowledged', 'resolved']), fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterFrom, fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->filterFrom)->startOfDay()))
            ->when($this->filterTo, fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->filterTo)->endOfDay()))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', "%$term%")->orWhere('message', 'like', "%$term%")));
    }

    public function acknowledge(int $id): void
    {
        $alert = Alert::findOrFail($id);
        if ($alert->status === 'open') {
            $alert->update(['status' => 'acknowledged', 'acknowledged_by' => auth()->id()]);
            $this->toast('Alerta marcada como atendida');
        }
    }

    public function resolve(int $id): void
    {
        $alert = Alert::findOrFail($id);
        if ($alert->status !== 'resolved') {
            $alert->update(['status' => 'resolved', 'resolved_at' => now(), 'acknowledged_by' => $alert->acknowledged_by ?? auth()->id()]);
            Audit::log('updated', "resolvió la alerta: {$alert->title}");
            $this->toast('Alerta resuelta');
        }
        $this->selected = array_values(array_diff($this->selected, [(string) $id, $id]));
    }

    public function reopen(int $id): void
    {
        Alert::whereKey($id)->update(['status' => 'open', 'resolved_at' => null]);
        $this->toast('Alerta reabierta', 'info');
    }

    public function resolveSelected(): void
    {
        $ids = array_map('intval', $this->selected);
        if (! $ids) {
            return;
        }
        $n = Alert::query()->whereIn('id', $ids)->where('status', '!=', 'resolved')
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'acknowledged_by' => DB::raw('IFNULL(acknowledged_by, '.(int) auth()->id().')')]);
        Audit::log('updated', "resolvió $n alertas en lote");
        $this->selected = [];
        $this->toast("$n alertas resueltas");
    }

    public function acknowledgeSelected(): void
    {
        $n = Alert::query()->whereIn('id', array_map('intval', $this->selected))->where('status', 'open')
            ->update(['status' => 'acknowledged', 'acknowledged_by' => auth()->id()]);
        $this->selected = [];
        $this->toast("$n alertas marcadas como atendidas");
    }

    public function analyze(SmartAlertsService $service): void
    {
        $r = $service->run();
        $raised = collect($r)->sum('raised');
        $resolved = collect($r)->sum('resolved');
        $this->toast("Análisis completado: $raised alertas vigentes, $resolved resueltas automáticamente");
    }

    public function render()
    {
        $openBySeverity = Alert::query()->open()->selectRaw('severity, COUNT(*) n')->groupBy('severity')->pluck('n', 'severity');
        $openByType = Alert::query()->open()->selectRaw('type, COUNT(*) n')->groupBy('type')->orderByDesc('n')->pluck('n', 'type');

        // Tendencia 14 días por severidad
        $from = now()->subDays(13)->startOfDay();
        $rows = Alert::query()->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) d, severity, COUNT(*) n')->groupBy(DB::raw('DATE(created_at)'), 'severity')->get();
        $days = collect(range(0, 13))->map(fn ($i) => $from->copy()->addDays($i));
        $series = collect(['critical' => ['Crítica', '#E5484D'], 'warning' => ['Advertencia', '#F7881F'], 'info' => ['Info', '#1A7FF5']])
            ->map(fn ($s, $sev) => [
                'name' => $s[0], 'type' => 'bar', 'stack' => 'a', 'barMaxWidth' => 18, 'itemStyle' => ['color' => $s[1]],
                'data' => $days->map(fn ($d) => (int) ($rows->first(fn ($r) => $r->d === $d->toDateString() && $r->severity === $sev)?->n ?? 0))->values(),
            ])->values();

        $alerts = $this->applySort($this->query(), 'created_at', 'desc')->paginate($this->perPage);

        return view('livewire.alerts.index', [
            'alerts' => $alerts,
            'groups' => $this->grouped ? $alerts->getCollection()->groupBy('type') : null,
            'openBySeverity' => $openBySeverity,
            'openByType' => $openByType,
            'resolvedWeek' => Alert::query()->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(7))->count(),
            'types' => RuleCatalog::alertTypes(),
            'chart' => [
                'tooltip' => ['trigger' => 'axis'],
                'legend' => ['bottom' => 0, 'itemWidth' => 10, 'itemHeight' => 10, 'textStyle' => ['fontSize' => 11]],
                'grid' => ['left' => 4, 'right' => 8, 'top' => 10, 'bottom' => 28, 'containLabel' => true],
                'xAxis' => ['type' => 'category', 'data' => $days->map(fn ($d) => $d->format('d/m'))->values(), 'axisTick' => ['show' => false]],
                'yAxis' => ['type' => 'value', 'minInterval' => 1, 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
                'series' => $series,
            ],
        ]);
    }
}
