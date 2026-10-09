<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\Toasts;
use App\Models\CollectionVisit;
use App\Models\InstallationOrder;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Field\FieldPlanner;
use App\Services\Field\StaffMetrics;
use App\Support\Audit;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ficha de un técnico o cobrador: ubicación, ruta del día, carga y desempeño.
 */
class Show extends Component
{
    use Toasts;

    public User $user;

    #[Url(as: 'periodo')]
    public string $period = 'mes';

    public function mount(User $user): void
    {
        abort_unless((int) $user->tenant_id === (int) tenant()->id && in_array($user->role, ['tecnico', 'cobrador', 'supervisor'], true), 404);
        $this->user = $user;
    }

    public function toggleOnCall(): void
    {
        $this->authorize('tickets.manage');
        abort_unless($this->user->role === 'tecnico', 403);
        $value = ! $this->user->on_call;
        if ($value) {
            User::forTenant()->where('on_call', true)->where('id', '!=', $this->user->id)->update(['on_call' => false]);
        }
        $this->user->forceFill(['on_call' => $value])->save();
        Audit::log('on_call', ($value ? 'asignó guardia a ' : 'retiró guardia a ').$this->user->name, $this->user);
        $this->toast($value ? 'Asignado como técnico de guardia' : 'Guardia retirada', $value ? 'success' : 'warning');
    }

    public function render(StaffMetrics $metrics, FieldPlanner $planner)
    {
        $u = $this->user;
        $range = StaffMetrics::range($this->period);
        $isTech = $u->role !== 'cobrador';
        $stats = $isTech ? $metrics->technicians(collect([$u->id]), $range)[$u->id] : $metrics->collectors(collect([$u->id]), $range)[$u->id];

        $route = $planner->routeFor($u, today(), $isTech ? 'tecnico' : 'cobranza');
        $stops = $route ? $route->stops->map(fn ($s) => $planner->stopPoint($s))->filter()->values()->all() : [];
        $start = $route?->start_lat ? ['lat' => (float) $route->start_lat, 'lng' => (float) $route->start_lng, 'label' => 'Inicio de ruta'] : null;
        $map = $planner->mapData($stops, $start);
        if ($u->last_lat) {
            $map['markers'][] = ['lat' => (float) $u->last_lat, 'lng' => (float) $u->last_lng, 'type' => $isTech ? 'technician' : 'collector',
                'color' => $isTech ? '#1A7FF5' : '#6048D0', 'size' => 32, 'pulse' => true, 'layer' => 'ubicacion',
                'popup' => '<b>'.e($u->name).'</b><br>Última ubicación '.($u->last_location_at?->diffForHumans() ?? '')];
        }

        // Serie diaria (últimos 30 días)
        $days = collect(range(29, 0))->map(fn ($d) => today()->subDays($d));
        $from = today()->subDays(29);
        $daily = $isTech
            ? Ticket::query()->where('technician_id', $u->id)->where('resolved_at', '>=', $from)->selectRaw('DATE(resolved_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd')
            : Payment::query()->revenue()->where('collector_id', $u->id)->where('paid_at', '>=', $from)->selectRaw('DATE(paid_at) d, SUM(amount) n')->groupBy('d')->pluck('n', 'd');
        $chart = [
            'grid' => ['left' => 4, 'right' => 8, 'top' => 16, 'bottom' => 4, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $days->map(fn ($d) => $d->format('d/m'))->all(), 'axisTick' => ['show' => false]],
            'yAxis' => ['type' => 'value', 'minInterval' => 1, 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
            'series' => [[
                'name' => $isTech ? 'Tickets resueltos' : 'Cobrado', 'type' => 'bar', 'barMaxWidth' => 14,
                'itemStyle' => ['borderRadius' => [4, 4, 0, 0], 'color' => $isTech ? '#22A06B' : '#6048D0'],
                'data' => $days->map(fn ($d) => round((float) ($daily[$d->toDateString()] ?? 0), 2))->all(),
            ]],
        ];

        $data = compact('u', 'stats', 'route', 'stops', 'map', 'chart', 'isTech');
        if ($isTech) {
            $data['openTickets'] = Ticket::query()->open()->where('technician_id', $u->id)->with('client:id,code,first_name,last_name', 'repeater:id,code')
                ->orderByRaw("FIELD(priority, 'critica', 'alta', 'media', 'baja')")->orderBy('sla_due_at')->get();
            $data['resolvedTickets'] = Ticket::query()->where('technician_id', $u->id)->whereBetween('resolved_at', $range)
                ->with('client:id,first_name,last_name')->latest('resolved_at')->limit(15)->get();
            $data['installations'] = InstallationOrder::query()->where('technician_id', $u->id)
                ->where(fn ($q) => $q->whereIn('status', FieldPlanner::INSTALL_STATUSES)->orWhereBetween('completed_at', $range))
                ->with('client:id,first_name,last_name', 'prospect:id,name')->orderByRaw("FIELD(status, 'en_proceso', 'programada', 'aceptada')")->orderBy('scheduled_at')->limit(15)->get();
        } else {
            $data['payments'] = Payment::query()->revenue()->where('collector_id', $u->id)->whereBetween('paid_at', $range)
                ->with('client:id,code,first_name,last_name')->latest('paid_at')->limit(20)->get();
            $data['visits'] = CollectionVisit::query()->where('collector_id', $u->id)->whereBetween('visited_at', $range)
                ->with('client:id,code,first_name,last_name')->latest('visited_at')->limit(20)->get();
        }

        return view('livewire.staff.show', $data)->title($u->name);
    }
}
