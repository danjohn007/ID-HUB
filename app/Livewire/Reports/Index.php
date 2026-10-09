<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\Toasts;
use App\Models\Plan;
use App\Models\Zone;
use App\Services\ReportExporter;
use App\Services\Reports\ReportCatalog;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reportes (sección 44): catálogo con filtros, vista previa (50 filas) y
 * exportación a Excel, CSV o PDF (con KPIs de resumen).
 */
#[Title('Reportes')]
class Index extends Component
{
    use Toasts;

    public const PREVIEW = 50;

    public const EXPORT_LIMIT = 50000;

    #[Url(as: 'reporte')]
    public string $report = 'clientes';

    public array $f = ['from' => '', 'to' => '', 'zone_id' => '', 'plan_id' => '', 'status' => '', 'type' => ''];

    public function mount(ReportCatalog $catalog): void
    {
        if (! $catalog->get($this->report)) {
            $this->report = 'clientes';
        }
        $this->resetFilters();
    }

    public function select(string $key): void
    {
        if (app(ReportCatalog::class)->get($key)) {
            $this->report = $key;
            $this->resetFilters();
        }
    }

    public function resetFilters(): void
    {
        [$from, $to] = app(ReportCatalog::class)->defaultDates($this->report);
        $this->f = ['from' => $from, 'to' => $to, 'zone_id' => '', 'plan_id' => '', 'status' => '', 'type' => ''];
    }

    protected function filters(): array
    {
        $this->validate([
            'f.from' => 'nullable|date', 'f.to' => 'nullable|date',
            'f.zone_id' => 'nullable|integer', 'f.plan_id' => 'nullable|integer',
            'f.status' => 'nullable|string|max:40', 'f.type' => 'nullable|string|max:40',
        ], [], ['f.from' => 'desde', 'f.to' => 'hasta']);

        return array_map(fn ($v) => $v === '' ? null : $v, $this->f);
    }

    public function export(string $format, ReportCatalog $catalog, ReportExporter $exporter)
    {
        $def = $catalog->get($this->report);
        abort_unless($def && in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);
        $f = $this->filters();
        $query = $catalog->query($this->report, $f);
        $rows = $query instanceof Builder ? (clone $query)->limit(self::EXPORT_LIMIT)->get() : $query;
        $summary = $catalog->summary($this->report, $query, $f);
        $title = 'Reporte de '.mb_strtolower($def['label']).($f['from'] || $f['to'] ? ' · '.$this->periodLabel($f) : '');

        Audit::log('export', "exportó el reporte {$def['label']} ({$format}, ".$rows->count().' registros)');

        return $exporter->download('reporte-'.$this->report, $format, $catalog->columns($this->report), $rows, $title, $summary);
    }

    protected function periodLabel(array $f): string
    {
        $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '…';

        return $fmt($f['from']).' – '.$fmt($f['to']);
    }

    public function render(ReportCatalog $catalog, ReportExporter $exporter)
    {
        $def = $catalog->get($this->report);
        $error = null;
        $total = 0;
        $preview = ['headers' => [], 'rows' => []];
        $summary = [];
        try {
            $f = $this->filters();
            $query = $catalog->query($this->report, $f);
            if ($query instanceof Builder) {
                $total = (clone $query)->reorder()->setEagerLoads([])->count();
                $rows = (clone $query)->limit(self::PREVIEW)->get();
            } else {
                $total = $query->count();
                $rows = $query->take(self::PREVIEW);
            }
            $preview = $exporter->table($catalog->columns($this->report), $rows);
            $summary = $catalog->summary($this->report, $query, $f);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $error = 'Revisa los filtros: '.collect($e->errors())->flatten()->first();
        }

        return view('livewire.reports.index', [
            'catalog' => collect($catalog->all())->groupBy('group', true),
            'def' => $def,
            'preview' => $preview,
            'total' => $total,
            'summary' => $summary,
            'error' => $error,
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'plans' => Plan::orderBy('price')->pluck('name', 'id'),
        ]);
    }
}
