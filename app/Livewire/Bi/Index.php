<?php

namespace App\Livewire\Bi;

use App\Services\Analytics\BiService;
use App\Services\ReportExporter;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Business Intelligence (sección 47): rentabilidad por zona y repetidor,
 * crecimiento y análisis de expansión (secciones 18, 20 y flujo "Expansión" 53).
 */
#[Title('Business Intelligence')]
class Index extends Component
{
    public const TABS = [
        'zonas' => 'Rentabilidad por zona',
        'repetidores' => 'Rentabilidad por repetidor',
        'crecimiento' => 'Crecimiento',
        'expansion' => 'Análisis de expansión',
    ];

    #[Url]
    public string $tab = 'zonas';

    #[Url(as: 'periodo', except: 'mes_anterior')]
    public string $period = 'mes_anterior';

    #[Url(as: 'meses', except: 12)]
    public int $months = 12;

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'zonas';
        }
        if (! array_key_exists($this->period, BiService::periods())) {
            $this->period = 'mes_anterior';
        }
        $this->months = in_array($this->months, [12, 24], true) ? $this->months : 12;
    }

    public function export(string $what, string $format = 'xlsx')
    {
        $bi = app(BiService::class);
        [$from, $to, $months] = $bi->range($this->period);
        $period = BiService::periods()[$this->period].' ('.$from->format('d/m/Y').' - '.$to->format('d/m/Y').')';
        $exporter = app(ReportExporter::class);

        return match ($what) {
            'repetidores' => $exporter->download('bi-repetidores', $format, [
                'Repetidor' => 'code', 'Nombre' => 'name', 'Zona' => 'zone', 'Clientes' => 'clients', 'Capacidad' => 'capacity',
                'Utilización %' => 'utilization', 'MRR' => 'mrr', 'Ingresos' => 'revenue', 'Costo' => 'cost', 'Margen' => 'margin', 'Margen %' => 'margin_pct',
            ], $bi->repeaters($from, $to, $months), 'Rentabilidad por repetidor · '.$period),
            'crecimiento' => $exporter->download('bi-crecimiento', $format, [
                'Mes' => 'label', 'Altas' => 'adds', 'Bajas' => 'cancels', 'Crecimiento neto' => 'net', 'Clientes' => 'clients', 'MRR' => 'mrr', 'Crec. MRR %' => 'mrr_growth',
            ], $bi->growth($this->months), 'Crecimiento '.$this->months.' meses'),
            'expansion' => $exporter->download('bi-expansion', $format, [
                'Recomendación' => 'recommendation', 'Puntaje' => 'score', 'Interesados' => 'demand', 'Últimos 30 días' => 'recent', 'MRR potencial' => 'potential_mrr',
                'Repetidor más cercano' => 'nearest', 'Distancia km' => 'distance_km', 'Capacidad libre cercana' => 'spare_nearby', 'Clientes en 1.5 km' => 'existing_clients',
                'Inversión' => 'investment', 'Recuperación (meses)' => 'payback_months', 'Lat' => 'lat', 'Lng' => 'lng',
            ], $bi->expansion()['candidates'], 'Análisis de expansión'),
            default => $exporter->download('bi-zonas', $format, [
                'Zona' => 'name', 'Clientes' => 'clients', 'Repetidores' => 'repeaters', 'MRR' => 'mrr', 'Ingresos' => 'revenue', 'Costos' => 'cost',
                'Margen' => 'margin', 'Margen %' => 'margin_pct', 'Altas' => 'adds', 'Cancelaciones' => 'cancels', 'Morosidad %' => 'delinquency', 'Saldo vencido' => 'overdue',
            ], $bi->zones($from, $to, $months), 'Rentabilidad por zona · '.$period),
        };
    }

    public function render(BiService $bi)
    {
        [$from, $to, $months] = $bi->range($this->period);
        $data = ['from' => $from, 'to' => $to, 'periodMonths' => $months];

        if ($this->tab === 'zonas') {
            $zones = collect($bi->zones($from, $to, $months));
            $data['zones'] = $zones;
            $data['zoneChart'] = $this->zoneChart($zones);
        } elseif ($this->tab === 'repetidores') {
            $reps = collect($bi->repeaters($from, $to, $months));
            $data['repeaters'] = $reps;
            $data['scatter'] = $this->scatterChart($reps);
        } elseif ($this->tab === 'crecimiento') {
            $growth = collect($bi->growth($this->months));
            $data['growth'] = $growth;
            $data['growthChart'] = $this->growthChart($growth);
            $data['mrrChart'] = $this->mrrChart($growth);
        } else {
            $data['exp'] = $bi->expansion();
        }

        return view('livewire.bi.index', $data);
    }

    protected function zoneChart($zones): array
    {
        return [
            'legend' => ['top' => 0, 'right' => 0, 'itemWidth' => 10, 'itemHeight' => 10],
            'grid' => ['left' => 4, 'right' => 4, 'top' => 34, 'bottom' => 4, 'containLabel' => true],
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow']],
            'xAxis' => ['type' => 'category', 'data' => $zones->pluck('name'), 'axisTick' => ['show' => false]],
            'yAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'axisLabel' => ['formatter' => '${value}']],
            'series' => [
                ['name' => 'Ingresos', 'type' => 'bar', 'barMaxWidth' => 26, 'itemStyle' => ['color' => '#1A7FF5', 'borderRadius' => [4, 4, 0, 0]], 'data' => $zones->pluck('revenue')],
                ['name' => 'Costos', 'type' => 'bar', 'barMaxWidth' => 26, 'itemStyle' => ['color' => '#F7881F', 'borderRadius' => [4, 4, 0, 0]], 'data' => $zones->pluck('cost')],
                ['name' => 'Margen', 'type' => 'line', 'symbolSize' => 8, 'itemStyle' => ['color' => '#22A06B'], 'lineStyle' => ['width' => 2.5], 'data' => $zones->pluck('margin')],
            ],
        ];
    }

    protected function scatterChart($reps): array
    {
        $point = fn ($r) => [
            'name' => $r['code'],
            'value' => [$r['utilization'], $r['mrr'], $r['clients'], $r['margin']],
            'symbolSize' => max(10, min(46, sqrt(max(1, $r['clients'])) * 4.5)),
        ];

        $series = fn (string $name, string $color, $rows) => [
            'name' => $name, 'type' => 'scatter', 'itemStyle' => ['color' => $color],
            'dimensions' => ['Utilización %', 'MRR', 'Clientes', 'Margen'],
            'encode' => ['x' => 0, 'y' => 1, 'tooltip' => [0, 1, 2, 3]],
            'label' => ['show' => true, 'formatter' => '{b}', 'position' => 'top', 'fontSize' => 10, 'color' => '#475569'],
            'data' => $rows->map($point)->values(),
        ];

        return [
            'legend' => ['top' => 0, 'right' => 0, 'itemWidth' => 10, 'itemHeight' => 10],
            'grid' => ['left' => 8, 'right' => 24, 'top' => 36, 'bottom' => 26, 'containLabel' => true],
            'tooltip' => ['trigger' => 'item'],
            'xAxis' => ['type' => 'value', 'name' => 'Utilización %', 'nameLocation' => 'middle', 'nameGap' => 26, 'min' => 0, 'max' => 120,
                'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
            'yAxis' => ['type' => 'value', 'name' => 'MRR ($)', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'axisLabel' => ['formatter' => '${value}']],
            'series' => [
                $series('Rentable', 'rgba(34,160,107,0.75)', $reps->where('margin', '>=', 0)->where('saturated', false)),
                $series('Saturado', 'rgba(247,136,31,0.8)', $reps->where('saturated', true)),
                $series('Margen negativo', 'rgba(229,72,77,0.8)', $reps->where('margin', '<', 0)->where('saturated', false)) + [
                    'markLine' => ['silent' => true, 'symbol' => 'none', 'lineStyle' => ['color' => '#E5484D', 'type' => 'dashed'],
                        'label' => ['formatter' => 'Umbral de saturación', 'fontSize' => 10], 'data' => [['xAxis' => 85]]],
                ],
            ],
        ];
    }

    protected function growthChart($growth): array
    {
        return [
            'legend' => ['top' => 0, 'right' => 0, 'itemWidth' => 10, 'itemHeight' => 10],
            'grid' => ['left' => 4, 'right' => 4, 'top' => 34, 'bottom' => 4, 'containLabel' => true],
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow']],
            'xAxis' => ['type' => 'category', 'data' => $growth->pluck('label'), 'axisTick' => ['show' => false]],
            'yAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
            'series' => [
                ['name' => 'Altas', 'type' => 'bar', 'barMaxWidth' => 16, 'itemStyle' => ['color' => '#22A06B', 'borderRadius' => [3, 3, 0, 0]], 'data' => $growth->pluck('adds')],
                ['name' => 'Bajas', 'type' => 'bar', 'barMaxWidth' => 16, 'itemStyle' => ['color' => '#E5484D', 'borderRadius' => [3, 3, 0, 0]], 'data' => $growth->map(fn ($g) => -$g['cancels'])],
                ['name' => 'Crecimiento neto', 'type' => 'line', 'smooth' => true, 'symbolSize' => 6, 'itemStyle' => ['color' => '#1A7FF5'], 'lineStyle' => ['width' => 2.5], 'data' => $growth->pluck('net')],
            ],
        ];
    }

    protected function mrrChart($growth): array
    {
        return [
            'legend' => ['top' => 0, 'right' => 0, 'itemWidth' => 10, 'itemHeight' => 10],
            'grid' => ['left' => 4, 'right' => 4, 'top' => 34, 'bottom' => 4, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $growth->pluck('label'), 'boundaryGap' => false, 'axisTick' => ['show' => false]],
            'yAxis' => [
                ['type' => 'value', 'scale' => true, 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'axisLabel' => ['formatter' => '${value}']],
                ['type' => 'value', 'scale' => true, 'splitLine' => ['show' => false]],
            ],
            'series' => [
                ['name' => 'MRR', 'type' => 'line', 'smooth' => true, 'symbolSize' => 5, 'itemStyle' => ['color' => '#6048D0'], 'lineStyle' => ['width' => 3], 'data' => $growth->pluck('mrr'),
                    'areaStyle' => ['color' => ['type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1, 'colorStops' => [['offset' => 0, 'color' => 'rgba(96,72,208,0.22)'], ['offset' => 1, 'color' => 'rgba(96,72,208,0.02)']]]]],
                ['name' => 'Clientes', 'type' => 'line', 'yAxisIndex' => 1, 'smooth' => true, 'symbol' => 'none', 'itemStyle' => ['color' => '#00A0A8'], 'lineStyle' => ['width' => 2, 'type' => 'dashed'], 'data' => $growth->pluck('clients')],
            ],
        ];
    }
}
