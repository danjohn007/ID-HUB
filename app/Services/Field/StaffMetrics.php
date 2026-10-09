<?php

namespace App\Services\Field;

use App\Models\CollectionVisit;
use App\Models\FieldRoute;
use App\Models\InstallationOrder;
use App\Models\Payment;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Carga de trabajo y desempeño de técnicos y cobradores (agregados por usuario).
 */
class StaffMetrics
{
    public static function periods(): array
    {
        return ['hoy' => 'Hoy', '7d' => 'Últimos 7 días', 'mes' => 'Este mes', '30d' => 'Últimos 30 días', 'mes_anterior' => 'Mes anterior', '90d' => 'Últimos 90 días'];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function range(string $period): array
    {
        return match ($period) {
            'hoy' => [today(), now()->endOfDay()],
            '7d' => [now()->subDays(7)->startOfDay(), now()],
            '30d' => [now()->subDays(30)->startOfDay(), now()],
            '90d' => [now()->subDays(90)->startOfDay(), now()],
            'mes_anterior' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [now()->startOfMonth(), now()],
        };
    }

    /** Métricas de técnicos indexadas por user_id. */
    public function technicians(Collection $ids, array $range): array
    {
        $ids = $ids->all();
        $open = Ticket::query()->open()->whereIn('technician_id', $ids)->selectRaw("technician_id, COUNT(*) n, SUM(priority = 'critica') crit")->groupBy('technician_id')->get()->keyBy('technician_id');
        $resolved = Ticket::query()->whereIn('technician_id', $ids)->whereBetween('resolved_at', $range)
            ->selectRaw('technician_id, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) avg_min, SUM(sla_breached = 0) in_sla')
            ->groupBy('technician_id')->get()->keyBy('technician_id');
        $installs = InstallationOrder::query()->whereIn('technician_id', $ids)->whereBetween('completed_at', $range)
            ->selectRaw('technician_id, COUNT(*) n')->groupBy('technician_id')->pluck('n', 'technician_id');
        $pendingInstalls = InstallationOrder::query()->whereIn('technician_id', $ids)->whereIn('status', FieldPlanner::INSTALL_STATUSES)
            ->selectRaw('technician_id, COUNT(*) n')->groupBy('technician_id')->pluck('n', 'technician_id');

        $out = [];
        foreach ($ids as $id) {
            $r = $resolved[$id] ?? null;
            $out[$id] = [
                'open' => (int) ($open[$id]->n ?? 0),
                'critical' => (int) ($open[$id]->crit ?? 0),
                'resolved' => (int) ($r->n ?? 0),
                'avg_min' => $r ? (float) $r->avg_min : null,
                'sla_pct' => $r && $r->n ? round($r->in_sla / $r->n * 100, 1) : null,
                'installs' => (int) ($installs[$id] ?? 0),
                'pending_installs' => (int) ($pendingInstalls[$id] ?? 0),
            ] + $this->todayStops($id);
        }

        return $out;
    }

    /** Métricas de cobradores indexadas por user_id. */
    public function collectors(Collection $ids, array $range): array
    {
        $ids = $ids->all();
        $paid = Payment::query()->revenue()->whereIn('collector_id', $ids)->whereBetween('paid_at', $range)
            ->selectRaw('collector_id, COUNT(*) n, SUM(amount) total')->groupBy('collector_id')->get()->keyBy('collector_id');
        $visits = CollectionVisit::query()->whereIn('collector_id', $ids)->whereBetween('visited_at', $range)
            ->selectRaw("collector_id, COUNT(*) n, SUM(result = 'pago') paid, SUM(result = 'promesa') promises")->groupBy('collector_id')->get()->keyBy('collector_id');
        $portfolio = Client::query()->whereIn('collector_id', $ids)->where('balance', '>', 0)
            ->selectRaw('collector_id, COUNT(*) n, SUM(balance) due')->groupBy('collector_id')->get()->keyBy('collector_id');

        $out = [];
        foreach ($ids as $id) {
            $v = $visits[$id] ?? null;
            $out[$id] = [
                'collected' => (float) ($paid[$id]->total ?? 0),
                'payments' => (int) ($paid[$id]->n ?? 0),
                'visits' => (int) ($v->n ?? 0),
                'effectiveness' => $v && $v->n ? round($v->paid / $v->n * 100, 1) : null,
                'promises' => (int) ($v->promises ?? 0),
                'pending_clients' => (int) ($portfolio[$id]->n ?? 0),
                'pending_amount' => (float) ($portfolio[$id]->due ?? 0),
            ] + $this->todayStops($id);
        }

        return $out;
    }

    protected function todayStops(int $userId): array
    {
        $routeIds = FieldRoute::query()->where('user_id', $userId)->whereDate('date', today())->pluck('id');
        if ($routeIds->isEmpty()) {
            return ['stops' => 0, 'stops_done' => 0, 'has_route' => false];
        }
        $stops = RouteStop::query()->whereIn('field_route_id', $routeIds)->selectRaw("COUNT(*) n, SUM(status = 'done') done")->first();

        return ['stops' => (int) $stops->n, 'stops_done' => (int) $stops->done, 'has_route' => true];
    }
}
