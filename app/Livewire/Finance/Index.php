<?php

namespace App\Livewire\Finance;

use App\Services\MetricsService;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard financiero (sección 30): MRR, ARR, churn, ARPU, LTV, ticket promedio,
 * morosidad, recuperación de cartera, ingresos por zona/plan/método, facturado vs
 * cobrado, tendencia de MRR y comisiones de pagos en línea.
 */
#[Title('Dashboard financiero')]
class Index extends Component
{
    #[Url(as: 'periodo', except: 'mes')]
    public string $period = 'mes';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public const PERIODS = [
        'mes' => 'Mes actual',
        'anterior' => 'Mes anterior',
        'trimestre' => 'Trimestre',
        'anio' => 'Año',
        'rango' => 'Rango',
    ];

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = 'mes';
        }
    }

    public function setPeriod(string $period): void
    {
        $this->period = array_key_exists($period, self::PERIODS) ? $period : 'mes';
        if ($this->period === 'rango' && ! $this->from) {
            $this->from = now()->subMonth()->toDateString();
            $this->to = now()->toDateString();
        }
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} desde, hasta, desde anterior, hasta anterior */
    protected function range(): array
    {
        [$from, $to] = match ($this->period) {
            'anterior' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'trimestre' => [now()->firstOfQuarter()->startOfDay(), now()],
            'anio' => [now()->startOfYear(), now()],
            'rango' => [
                $this->safeDate($this->from) ?? now()->startOfMonth(),
                ($this->safeDate($this->to) ?? now())->endOfDay(),
            ],
            default => [now()->startOfMonth(), now()],
        };
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        // Periodo de comparación equivalente (mismo avance del mes/trimestre/año anterior)
        [$prevFrom, $prevTo] = match ($this->period) {
            'mes', 'anterior' => [$from->copy()->subMonthNoOverflow(), $to->copy()->subMonthNoOverflow()],
            'trimestre' => [$from->copy()->subMonthsNoOverflow(3), $to->copy()->subMonthsNoOverflow(3)],
            'anio' => [$from->copy()->subYear(), $to->copy()->subYear()],
            default => (function () use ($from, $to) {
                $days = max(1, (int) $from->diffInDays($to) + 1);
                $prevTo = $from->copy()->subSecond();

                return [$prevTo->copy()->subDays($days - 1)->startOfDay(), $prevTo];
            })(),
        };
        if ($this->period === 'anterior') {
            $prevTo = $prevFrom->copy()->endOfMonth();
        }

        return [$from, $to, $prevFrom, $prevTo];
    }

    protected function safeDate(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function change(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    public function render(MetricsService $m)
    {
        [$from, $to, $prevFrom, $prevTo] = $this->range();

        $mrr = $m->mrr();
        $live = $m->liveClients();
        $arpu = $m->arpu();
        $collected = $m->collected($from, $to);
        $collectedPrev = $m->collected($prevFrom, $prevTo);
        $billed = $m->billed($from, $to);
        $del = $m->delinquency();
        $churn = $m->churnBetween($from, $to);
        $churnPrev = $m->churnBetween($prevFrom, $prevTo);
        $avgChurn = $m->averageChurn();
        $trend = collect($m->mrrTrend(12));
        $series = collect($m->monthlySeries(12));
        $prevMrr = $trend->slice(-2, 1)->first()['mrr'] ?? 0;
        $byZone = $m->revenueByZone($from, $to);
        $byPlan = $m->revenueByPlan($from, $to);
        $byMethod = $m->revenueByMethod($from, $to);
        $commissions = $m->commissions($from, $to);
        $avgTicket = $m->averageTicket($from, $to);

        $kpi = [
            'mrr' => $mrr,
            'mrr_change' => $this->change($mrr, (float) $prevMrr),
            'arr' => $mrr * 12,
            'churn' => $churn,
            'churn_prev' => $churnPrev,
            'cancellations' => $m->cancellations($from, $to),
            'arpu' => $arpu,
            'ltv' => $m->ltv(),
            'avg_churn' => $avgChurn,
            'avg_ticket' => $avgTicket,
            'avg_ticket_change' => $this->change($avgTicket, $m->averageTicket($prevFrom, $prevTo)),
            'live' => $live,
            'collected' => $collected,
            'collected_change' => $this->change($collected, $collectedPrev),
            'billed' => $billed,
            'collection_rate' => $billed > 0 ? round($collected / $billed * 100, 1) : null,
            'delinquency' => $del,
            'recovery' => $m->recoveryRateBetween($from, $to),
            'new' => $m->newClients($from, $to),
        ];

        return view('livewire.finance.index', [
            'kpi' => $kpi,
            'periodFrom' => $from,
            'periodTo' => $to,
            'byZone' => $byZone,
            'byPlan' => $byPlan,
            'byMethod' => $byMethod,
            'commissions' => $commissions,
            'series' => $series,
            'billedChart' => $this->billedChart($series),
            'mrrChart' => $this->mrrChart($trend),
            'zoneChart' => $this->barChart(collect($byZone), 'name', 'total', 'color'),
            'planChart' => $this->pieChart(collect($byPlan)),
            'methodChart' => $this->pieChart(collect($byMethod)->map(fn ($r) => ['name' => $r['label'], 'total' => $r['total'], 'color' => null])),
        ]);
    }

    protected function billedChart($series): array
    {
        return [
            'legend' => ['top' => 0, 'right' => 0, 'itemWidth' => 10, 'itemHeight' => 10, 'textStyle' => ['fontSize' => 11]],
            'grid' => ['left' => 4, 'right' => 4, 'top' => 34, 'bottom' => 4, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $series->pluck('label'), 'axisTick' => ['show' => false]],
            'yAxis' => [
                ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'axisLabel' => ['formatter' => '${value}']],
                ['type' => 'value', 'min' => 0, 'max' => 120, 'splitLine' => ['show' => false], 'axisLabel' => ['formatter' => '{value}%']],
            ],
            'series' => [
                ['name' => 'Facturado', 'type' => 'bar', 'barMaxWidth' => 18, 'itemStyle' => ['color' => '#C7DDFC', 'borderRadius' => [4, 4, 0, 0]], 'data' => $series->pluck('billed')],
                ['name' => 'Cobrado', 'type' => 'bar', 'barMaxWidth' => 18, 'itemStyle' => ['color' => '#1A7FF5', 'borderRadius' => [4, 4, 0, 0]], 'data' => $series->pluck('collected')],
                ['name' => '% cobranza', 'type' => 'line', 'yAxisIndex' => 1, 'smooth' => true, 'symbolSize' => 6, 'itemStyle' => ['color' => '#22A06B'], 'lineStyle' => ['width' => 2.5],
                    'data' => $series->map(fn ($s) => $s['billed'] > 0 ? round($s['collected'] / $s['billed'] * 100, 1) : null)],
            ],
        ];
    }

    protected function mrrChart($trend): array
    {
        return [
            'grid' => ['left' => 4, 'right' => 10, 'top' => 16, 'bottom' => 4, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $trend->pluck('label'), 'boundaryGap' => false, 'axisTick' => ['show' => false]],
            'yAxis' => ['type' => 'value', 'scale' => true, 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'axisLabel' => ['formatter' => '${value}']],
            'series' => [[
                'name' => 'MRR', 'type' => 'line', 'smooth' => true, 'symbolSize' => 6, 'data' => $trend->pluck('mrr'), 'lineStyle' => ['width' => 3], 'itemStyle' => ['color' => '#6048D0'],
                'areaStyle' => ['color' => ['type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1, 'colorStops' => [['offset' => 0, 'color' => 'rgba(96,72,208,0.25)'], ['offset' => 1, 'color' => 'rgba(96,72,208,0.02)']]]],
            ]],
        ];
    }

    protected function barChart($rows, string $label, string $value, ?string $color = null): array
    {
        $rows = $rows->take(12)->reverse()->values();

        return [
            'grid' => ['left' => 4, 'right' => 24, 'top' => 6, 'bottom' => 4, 'containLabel' => true],
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow']],
            'xAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'splitNumber' => 3, 'axisLabel' => ['show' => false]],
            'yAxis' => ['type' => 'category', 'data' => $rows->pluck($label), 'axisTick' => ['show' => false]],
            'series' => [[
                'name' => 'Cobrado', 'type' => 'bar', 'barMaxWidth' => 16,
                'data' => $rows->map(fn ($r) => ['value' => round((float) $r[$value], 2), 'itemStyle' => ['color' => $color ? ($r[$color] ?? '#1A7FF5') : '#1A7FF5', 'borderRadius' => [0, 4, 4, 0]]]),
            ]],
        ];
    }

    protected function pieChart($rows): array
    {
        return [
            'tooltip' => ['trigger' => 'item', 'formatter' => '{b}: ${c} ({d}%)'],
            'series' => [[
                'type' => 'pie', 'radius' => ['55%', '85%'], 'label' => ['show' => false], 'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2],
                'data' => $rows->map(fn ($r) => array_filter(['name' => $r['name'], 'value' => round((float) $r['total']), 'itemStyle' => ! empty($r['color']) ? ['color' => $r['color']] : null]))->values(),
            ]],
        ];
    }
}
