<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\Outage;
use App\Models\Payment;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\MetricsService;
use App\Support\MapData;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Panel principal (sección 55 del documento): KPIs, mapa operativo, estado de
 * repetidores, alertas inteligentes, ingresos, tickets y actividad reciente.
 */
#[Title('Panel Principal')]
class Dashboard extends Component
{
    public function render(MetricsService $metrics)
    {
        $kpi = $metrics->headline();
        $series = collect($metrics->monthlySeries(6));
        $byPlan = $metrics->mrrByPlan();
        $tickets = $metrics->ticketStats();

        $repeaters = Repeater::query()->withLoad()->orderBy('code')->get();

        // Alertas agrupadas por tipo (tarjeta "Alertas Inteligentes")
        $open = Alert::query()->open()->get();
        $alertGroups = collect([
            ['repeater_down', 'Repetidores caídos', 'exclamation-triangle', 'danger', 'network.repeaters.index'],
            ['abnormal_signal', 'Clientes con señal anormal', 'wifi', 'warning', 'network.index'],
            ['recurrent_delay', 'Clientes con retrasos recurrentes', 'clock', 'warning', 'billing.index'],
            ['sla_risk', 'Tickets próximos a vencer', 'ticket', 'brand', 'tickets.index'],
            ['low_stock', 'Inventario bajo', 'archive-box', 'purple', 'inventory.index'],
            ['zone_growth', 'Zonas con crecimiento acelerado', 'arrow-trending-up', 'success', 'map.index'],
        ])->map(fn ($g) => [
            'label' => $g[1], 'icon' => $g[2], 'color' => $g[3], 'route' => $g[4],
            'count' => $open->where('type', $g[0])->count(),
            'detail' => $open->where('type', $g[0])->take(3)->map(fn ($a) => str($a->title)->after(' ')->limit(28))->implode(', '),
        ]);

        return view('livewire.dashboard', [
            'kpi' => $kpi,
            'ops' => $metrics->operational(),
            'repeaters' => $repeaters,
            'alertGroups' => $alertGroups,
            'activity' => $this->activity(),
            'map' => [
                'markers' => array_merge(MapData::clients(), MapData::repeaters(), MapData::towers(), MapData::staff()),
                'circles' => MapData::coverageCircles(),
            ],
            'mrrChart' => [
                'grid' => ['left' => 4, 'right' => 10, 'top' => 14, 'bottom' => 4, 'containLabel' => true],
                'xAxis' => ['type' => 'category', 'data' => $series->pluck('label'), 'boundaryGap' => false, 'axisLine' => ['show' => false], 'axisTick' => ['show' => false]],
                'yAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']], 'axisLabel' => ['formatter' => '${value}']],
                'series' => [[
                    'name' => 'Facturado', 'type' => 'line', 'smooth' => true, 'symbolSize' => 6, 'data' => $series->pluck('billed'),
                    'areaStyle' => ['color' => ['type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1, 'colorStops' => [['offset' => 0, 'color' => 'rgba(26,127,245,0.28)'], ['offset' => 1, 'color' => 'rgba(26,127,245,0.02)']]]],
                    'lineStyle' => ['width' => 3],
                ]],
            ],
            'mrrChange' => ($prev = $series->slice(-2, 1)->first()['billed'] ?? 0) > 0 ? round(($series->last()['billed'] - $prev) / $prev * 100, 1) : null,
            'byPlan' => $byPlan,
            'planChart' => [
                'tooltip' => ['trigger' => 'item', 'formatter' => '{b}: ${c} ({d}%)'],
                'series' => [[
                    'type' => 'pie', 'radius' => ['58%', '85%'], 'avoidLabelOverlap' => true, 'label' => ['show' => false],
                    'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2],
                    'data' => collect($byPlan)->map(fn ($p) => ['name' => $p['name'], 'value' => round($p['mrr']), 'itemStyle' => ['color' => $p['color']]])->values(),
                ]],
            ],
            'tickets' => $tickets,
            'ticketChart' => [
                'tooltip' => ['trigger' => 'item'],
                'series' => [[
                    'type' => 'pie', 'radius' => ['58%', '85%'], 'label' => ['show' => false], 'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2],
                    'data' => collect(config('wisp.ticket_priorities'))->map(fn ($p, $k) => [
                        'name' => $p[0], 'value' => $tickets['by_priority'][$k] ?? 0,
                        'itemStyle' => ['color' => ['critica' => '#E5484D', 'alta' => '#F7881F', 'media' => '#FBBF24', 'baja' => '#22A06B'][$k]],
                    ])->values(),
                ]],
            ],
        ]);
    }

    protected function activity()
    {
        $items = collect();
        foreach (Payment::query()->revenue()->with('client:id,first_name,last_name')->latest('paid_at')->limit(4)->get() as $p) {
            $items->push(['at' => $p->paid_at, 'icon' => 'banknotes', 'color' => 'success', 'title' => 'Pago recibido - '.money($p->amount), 'sub' => 'Cliente: '.$p->client?->full_name]);
        }
        foreach (TicketComment::query()->whereHas('ticket', fn ($q) => $q->where('tenant_id', tenant()->id))->with('ticket:id,folio,subject')->latest()->limit(3)->get() as $c) {
            $items->push(['at' => $c->created_at, 'icon' => 'ticket', 'color' => 'brand', 'title' => 'Ticket '.$c->ticket?->folio.' actualizado', 'sub' => str($c->ticket?->subject.' - '.$c->body)->limit(48)]);
        }
        foreach (Client::query()->latest()->with('plan:id,name,download_mbps')->limit(2)->get() as $c) {
            $items->push(['at' => $c->created_at, 'icon' => 'user-plus', 'color' => 'brand', 'title' => 'Nuevo cliente registrado', 'sub' => $c->full_name.' - Plan '.$c->plan?->name.' '.$c->plan?->speedLabel()]);
        }
        foreach (Outage::query()->whereNull('ended_at')->with('repeater:id,code')->get() as $o) {
            $items->push(['at' => $o->started_at, 'icon' => 'exclamation-triangle', 'color' => 'danger', 'title' => 'Repetidor '.$o->repeater?->code.' fuera de servicio', 'sub' => 'Sin comunicación desde '.$o->started_at->diffForHumans()]);
        }
        foreach (Ticket::query()->where('status', 'en_proceso')->with('technician:id,name')->latest('arrived_at')->limit(1)->get() as $t) {
            $items->push(['at' => $t->arrived_at ?? $t->updated_at, 'icon' => 'truck', 'color' => 'brand', 'title' => 'Técnico en sitio', 'sub' => $t->technician?->name.' · '.$t->folio]);
        }

        return $items->sortByDesc('at')->take(7)->values();
    }
}
