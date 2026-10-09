<?php

namespace App\Services\Network;

use App\Models\MonitoringSample;
use Illuminate\Support\Collection;

/**
 * Series de monitoreo (monitoring_samples) agregadas en intervalos y opciones ECharts
 * listas para <x-ui.chart>. Compatible con MySQL 5.7 (sin funciones de ventana).
 */
class NetworkCharts
{
    public const COLORS = ['brand' => '#1A7FF5', 'success' => '#22A06B', 'danger' => '#E5484D', 'warning' => '#F7881F',
        'purple' => '#6048D0', 'teal' => '#00A0A8', 'navy' => '#0B1B3A', 'slate' => '#94A3B8'];

    /**
     * Serie agregada de las últimas $hours horas en intervalos de $minutes.
     * Promedia CPU/memoria/latencia/pérdida y suma tráfico/clientes entre equipos.
     */
    public static function aggregate(string $type, ?array $ids = null, int $hours = 24, int $minutes = 30): Collection
    {
        $minutes = max(5, min(60, $minutes));
        $bucket = "CONCAT(DATE_FORMAT(sampled_at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(sampled_at) / {$minutes}) * {$minutes}, 2, '0'))";
        $rows = MonitoringSample::query()
            ->where('subject_type', $type)
            ->when($ids !== null, fn ($q) => $q->whereIn('subject_id', $ids ?: [0]))
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->selectRaw("{$bucket} b, AVG(cpu) cpu, AVG(memory) memory, AVG(latency_ms) latency, AVG(packet_loss) loss,
                SUM(rx_bps) * COUNT(DISTINCT subject_id) / COUNT(*) rx, SUM(tx_bps) * COUNT(DISTINCT subject_id) / COUNT(*) tx,
                SUM(connected_clients) * COUNT(DISTINCT subject_id) / COUNT(*) clients, AVG(online) online")
            ->groupBy('b')->orderBy('b')->get();

        return $rows->map(fn ($r) => [
            'at' => $r->b,
            'label' => substr($r->b, 11, 5),
            'cpu' => $r->cpu !== null ? round((float) $r->cpu, 1) : null,
            'memory' => $r->memory !== null ? round((float) $r->memory, 1) : null,
            'latency' => $r->latency !== null ? round((float) $r->latency, 1) : null,
            'loss' => $r->loss !== null ? round((float) $r->loss, 2) : null,
            'rx' => $r->rx !== null ? round((float) $r->rx / 1e6, 1) : null,
            'tx' => $r->tx !== null ? round((float) $r->tx / 1e6, 1) : null,
            'clients' => $r->clients !== null ? (int) round((float) $r->clients) : null,
            'online' => round((float) $r->online * 100, 1),
        ]);
    }

    protected static function base(Collection $rows, string $unit = '', array $extra = []): array
    {
        return array_replace_recursive([
            'grid' => ['left' => 4, 'right' => 12, 'top' => 34, 'bottom' => 4, 'containLabel' => true],
            'legend' => ['top' => 0, 'right' => 0, 'icon' => 'circle', 'itemWidth' => 8, 'itemHeight' => 8, 'textStyle' => ['fontSize' => 11]],
            'xAxis' => ['type' => 'category', 'boundaryGap' => false, 'data' => $rows->pluck('label')->all(),
                'axisLine' => ['lineStyle' => ['color' => '#CBD5E1']], 'axisTick' => ['show' => false], 'axisLabel' => ['color' => '#64748B', 'fontSize' => 10]],
            'yAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']],
                'axisLabel' => ['color' => '#64748B', 'fontSize' => 10, 'formatter' => '{value}'.$unit]],
        ], $extra);
    }

    protected static function line(string $name, array $data, string $color, bool $area = false, array $extra = []): array
    {
        return array_merge([
            'name' => $name, 'type' => 'line', 'smooth' => true, 'showSymbol' => false, 'connectNulls' => true, 'data' => $data,
            'lineStyle' => ['width' => 2, 'color' => $color], 'itemStyle' => ['color' => $color],
        ], $area ? ['areaStyle' => ['color' => ['type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
            'colorStops' => [['offset' => 0, 'color' => $color.'40'], ['offset' => 1, 'color' => $color.'05']]]]] : [], $extra);
    }

    public static function traffic(Collection $rows): array
    {
        return self::base($rows, ' Mbps') + ['series' => [
            self::line('Descarga (rx)', $rows->pluck('rx')->all(), self::COLORS['brand'], true),
            self::line('Subida (tx)', $rows->pluck('tx')->all(), self::COLORS['teal'], true),
        ]];
    }

    public static function resources(Collection $rows): array
    {
        $o = self::base($rows, '%');
        $o['yAxis']['max'] = 100;

        return $o + ['series' => [
            self::line('CPU', $rows->pluck('cpu')->all(), self::COLORS['purple']),
            self::line('Memoria', $rows->pluck('memory')->all(), self::COLORS['warning']),
        ]];
    }

    public static function quality(Collection $rows, ?float $maxLatency = null): array
    {
        $o = self::base($rows, '', ['yAxis' => ['name' => 'ms', 'nameTextStyle' => ['color' => '#64748B', 'fontSize' => 10]]]);
        $o['yAxis'] = [$o['yAxis'], ['type' => 'value', 'name' => '% pérdida', 'min' => 0, 'nameTextStyle' => ['color' => '#64748B', 'fontSize' => 10],
            'splitLine' => ['show' => false], 'axisLabel' => ['color' => '#64748B', 'fontSize' => 10, 'formatter' => '{value}%']]];
        $latency = self::line('Latencia (ms)', $rows->pluck('latency')->all(), self::COLORS['brand'], true);
        if ($maxLatency) {
            $latency['markLine'] = ['silent' => true, 'symbol' => 'none', 'label' => ['formatter' => 'Máx. '.$maxLatency.' ms', 'fontSize' => 10, 'color' => self::COLORS['danger']],
                'lineStyle' => ['type' => 'dashed', 'color' => self::COLORS['danger']], 'data' => [['yAxis' => $maxLatency]]];
        }

        return $o + ['series' => [
            $latency,
            ['name' => 'Packet loss (%)', 'type' => 'bar', 'yAxisIndex' => 1, 'barMaxWidth' => 6, 'data' => $rows->pluck('loss')->all(),
                'itemStyle' => ['color' => self::COLORS['danger'], 'borderRadius' => [2, 2, 0, 0]]],
        ]];
    }

    public static function clients(Collection $rows): array
    {
        return self::base($rows) + ['series' => [
            self::line('Clientes conectados', $rows->pluck('clients')->all(), self::COLORS['success'], true, ['step' => false]),
        ]];
    }

    /** Barras de utilización por repetidor con línea de umbral. */
    public static function saturation(iterable $repeaters): array
    {
        $rows = collect($repeaters)->map(fn ($r) => [
            'code' => $r->code, 'util' => $r->status === 'offline' ? 0 : $r->utilization(), 'threshold' => (int) $r->alert_threshold, 'offline' => $r->status === 'offline',
        ])->values();
        $threshold = (int) ($rows->pluck('threshold')->mode()[0] ?? 85);

        return [
            'grid' => ['left' => 4, 'right' => 16, 'top' => 24, 'bottom' => 4, 'containLabel' => true],
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow']],
            'xAxis' => ['type' => 'category', 'data' => $rows->pluck('code')->all(), 'axisTick' => ['show' => false],
                'axisLabel' => ['color' => '#64748B', 'fontSize' => 10, 'interval' => 0, 'rotate' => $rows->count() > 12 ? 45 : 0]],
            'yAxis' => ['type' => 'value', 'max' => 110, 'interval' => 25, 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']],
                'axisLabel' => ['color' => '#64748B', 'fontSize' => 10, 'formatter' => '{value}%']],
            'series' => [[
                'name' => 'Utilización', 'type' => 'bar', 'barMaxWidth' => 26,
                'data' => $rows->map(fn ($r) => ['value' => $r['util'], 'itemStyle' => ['borderRadius' => [4, 4, 0, 0], 'color' => $r['offline'] ? self::COLORS['slate']
                    : ($r['util'] >= 95 ? self::COLORS['danger'] : ($r['util'] >= $r['threshold'] ? self::COLORS['warning'] : self::COLORS['success']))]])->all(),
                'label' => ['show' => true, 'position' => 'top', 'fontSize' => 9, 'color' => '#475569', 'formatter' => '{c}%'],
                'markLine' => ['silent' => true, 'symbol' => 'none', 'lineStyle' => ['type' => 'dashed', 'color' => self::COLORS['danger'], 'width' => 1.5],
                    'label' => ['formatter' => 'Umbral '.$threshold.'%', 'position' => 'insideEndTop', 'color' => self::COLORS['danger'], 'fontSize' => 10],
                    'data' => [['yAxis' => $threshold]]],
            ]],
        ];
    }
}
