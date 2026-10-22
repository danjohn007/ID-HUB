<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\NetworkDevice;
use App\Models\Payment;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del WISP activo (todas las consultas respetan el tenant del contexto).
 * Definiciones:
 *  - MRR: suma de la mensualidad equivalente de clientes con servicio vigente (activo, moroso, en revisión).
 *  - ARR: MRR × 12.
 *  - Churn: cancelaciones del mes / clientes vigentes al inicio del mes.
 *  - ARPU: MRR / clientes vigentes.   LTV: ARPU / churn mensual (promedio 6 meses).
 *  - Morosidad: (morosos + suspendidos) / clientes facturables.
 *  - Recuperación de cartera: cobrado de cargos vencidos en el periodo / (eso + saldo vencido actual).
 */
class MetricsService
{
    protected function tid(): ?int
    {
        return tenant()?->id;
    }

    public const LIVE = ['activo', 'moroso', 'en_revision'];

    public function mrr(): float
    {
        return (float) DB::table('clients')->join('plans', 'plans.id', '=', 'clients.plan_id')
            ->where('clients.tenant_id', $this->tid())->whereNull('clients.deleted_at')->whereIn('clients.service_status', self::LIVE)
            ->sum(DB::raw("plans.price / CASE plans.periodicity WHEN 'bimonthly' THEN 2 WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END"));
    }

    public function liveClients(): int
    {
        return Client::query()->whereIn('service_status', self::LIVE)->count();
    }

    public function statusCounts(): array
    {
        return Client::query()->selectRaw('service_status, COUNT(*) n')->groupBy('service_status')->pluck('n', 'service_status')->all();
    }

    public function newClients(?Carbon $from = null, ?Carbon $to = null): int
    {
        $from ??= now()->startOfMonth();
        $to ??= now();

        return Client::query()->whereBetween('installation_date', [$from->toDateString(), $to->toDateString()])->count();
    }

    public function newClientsPrevious(): int
    {
        $from = now()->subMonthNoOverflow()->startOfMonth();

        return $this->newClients($from, $from->copy()->endOfMonth());
    }

    public function churn(?Carbon $month = null): float
    {
        $month ??= now()->startOfMonth();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $cancelled = Client::query()->whereBetween('cancelled_at', [$start, $end])->count();
        $base = Client::query()->where('installation_date', '<', $start->toDateString())
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $start))->count();

        return $base > 0 ? round($cancelled / $base * 100, 2) : 0;
    }

    public function averageChurn(int $months = 6): float
    {
        $values = collect(range(1, $months))->map(fn ($i) => $this->churn(now()->subMonthsNoOverflow($i)));

        return round($values->avg(), 2);
    }

    public function arpu(): float
    {
        $live = $this->liveClients();

        return $live ? round($this->mrr() / $live, 2) : 0;
    }

    public function ltv(): float
    {
        $churn = $this->averageChurn() / 100;

        return $churn > 0 ? round($this->arpu() / $churn, 2) : round($this->arpu() * 60, 2);
    }

    public function collected(?Carbon $from = null, ?Carbon $to = null): float
    {
        return (float) Payment::query()->revenue()->whereBetween('paid_at', [$from ?? now()->startOfMonth(), $to ?? now()])->sum('amount');
    }

    public function averageTicket(?Carbon $from = null, ?Carbon $to = null): float
    {
        return round((float) Payment::query()->revenue()->whereBetween('paid_at', [$from ?? now()->startOfMonth(), $to ?? now()])->avg('amount'), 2);
    }

    public function delinquency(): array
    {
        $counts = $this->statusCounts();
        $morosos = ($counts['moroso'] ?? 0) + ($counts['suspendido'] ?? 0);
        $billable = collect(Client::BILLABLE)->sum(fn ($s) => $counts[$s] ?? 0);
        $overdueAmount = (float) DB::table('charges')->where('tenant_id', $this->tid())->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', now()->toDateString())->sum(DB::raw('total - paid_amount'));

        return [
            'count' => $morosos,
            'morosos' => $counts['moroso'] ?? 0,
            'suspendidos' => $counts['suspendido'] ?? 0,
            'rate' => $billable ? round($morosos / $billable * 100, 1) : 0,
            'amount' => $overdueAmount,
        ];
    }

    /** % de cartera vencida recuperada en el periodo. */
    public function recoveryRate(?Carbon $from = null): float
    {
        $from ??= now()->startOfMonth();
        $recovered = (float) DB::table('payment_allocations')
            ->join('charges', 'charges.id', '=', 'payment_allocations.charge_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.tenant_id', $this->tid())->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->where('payments.paid_at', '>=', $from)
            ->whereRaw('DATE(payments.paid_at) > charges.due_date')
            ->sum('payment_allocations.amount');
        $pending = $this->delinquency()['amount'];

        return ($recovered + $pending) > 0 ? round($recovered / ($recovered + $pending) * 100, 1) : 100;
    }

    /** Serie mensual: facturado (MRR facturado), cobrado, altas y bajas. */
    public function monthlySeries(int $months = 12): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1);
        $billed = DB::table('charges')->where('tenant_id', $this->tid())->where('type', 'mensualidad')->where('status', '!=', 'cancelled')
            ->where('period_start', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(period_start, '%Y-%m') ym, SUM(total) v")->groupBy('ym')->pluck('v', 'ym');
        $collected = DB::table('payments')->where('tenant_id', $this->tid())->where('status', 'completed')->where('kind', 'payment')
            ->where('paid_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') ym, SUM(amount) v")->groupBy('ym')->pluck('v', 'ym');
        $adds = DB::table('clients')->where('tenant_id', $this->tid())->whereNull('deleted_at')->where('installation_date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(installation_date, '%Y-%m') ym, COUNT(*) v")->groupBy('ym')->pluck('v', 'ym');
        $cancels = DB::table('clients')->where('tenant_id', $this->tid())->whereNull('deleted_at')->where('cancelled_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(cancelled_at, '%Y-%m') ym, COUNT(*) v")->groupBy('ym')->pluck('v', 'ym');

        $out = [];
        for ($m = $from->copy(); $m->lte(now()); $m->addMonth()) {
            $k = $m->format('Y-m');
            $out[] = [
                'key' => $k,
                'label' => ucfirst($m->translatedFormat('M')),
                'billed' => round((float) ($billed[$k] ?? 0), 2),
                'collected' => round((float) ($collected[$k] ?? 0), 2),
                'adds' => (int) ($adds[$k] ?? 0),
                'cancels' => (int) ($cancels[$k] ?? 0),
                'net' => (int) ($adds[$k] ?? 0) - (int) ($cancels[$k] ?? 0),
            ];
        }

        return $out;
    }

    /** MRR por plan (clientes vigentes). */
    public function mrrByPlan(): array
    {
        return DB::table('clients')->join('plans', 'plans.id', '=', 'clients.plan_id')
            ->where('clients.tenant_id', $this->tid())->whereNull('clients.deleted_at')->whereIn('clients.service_status', self::LIVE)
            ->selectRaw('plans.id, plans.name, plans.color, COUNT(*) clients, SUM('.$this->monthlyPriceSql().') mrr')
            ->groupBy('plans.id', 'plans.name', 'plans.color')->orderBy('plans.price')->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Ingresos cobrados por zona en el periodo. */
    public function revenueByZone(?Carbon $from = null, ?Carbon $to = null): array
    {
        return DB::table('payments')->join('clients', 'clients.id', '=', 'payments.client_id')
            ->leftJoin('zones', 'zones.id', '=', 'clients.zone_id')
            ->where('payments.tenant_id', $this->tid())->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->whereBetween('payments.paid_at', [$from ?? now()->startOfMonth(), $to ?? now()])
            ->selectRaw("COALESCE(zones.name, 'Sin zona') name, COALESCE(zones.color, '#94A3B8') color, SUM(payments.amount) total, COUNT(DISTINCT payments.client_id) clients")
            ->groupBy('zones.name', 'zones.color')->orderByDesc('total')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function revenueByMethod(?Carbon $from = null, ?Carbon $to = null): array
    {
        return Payment::query()->revenue()->whereBetween('paid_at', [$from ?? now()->startOfMonth(), $to ?? now()])
            ->selectRaw('method, SUM(amount) total, COUNT(*) n')->groupBy('method')->orderByDesc('total')->get()
            ->map(fn ($r) => ['method' => $r->method, 'label' => $r->methodLabel(), 'total' => (float) $r->total, 'n' => $r->n])->all();
    }

    public function ticketStats(): array
    {
        $open = Ticket::query()->open();

        return [
            'open' => (clone $open)->count(),
            'critical' => (clone $open)->where('priority', 'critica')->count(),
            'overdue' => (clone $open)->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
            'by_priority' => (clone $open)->selectRaw('priority, COUNT(*) n')->groupBy('priority')->pluck('n', 'priority')->all(),
        ];
    }

    public function networkStats(): array
    {
        $repeaters = Repeater::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $devices = NetworkDevice::query()->whereNotIn('status', ['retired', 'in_stock']);
        $total = (clone $devices)->count();
        $online = (clone $devices)->where('status', 'online')->count();
        $live = Client::query()->whereIn('service_status', self::LIVE);

        return [
            'repeaters' => (int) $repeaters->sum(),
            'repeaters_online' => (int) ($repeaters['online'] ?? 0),
            'repeaters_alert' => (int) (($repeaters['warning'] ?? 0) + ($repeaters['offline'] ?? 0)),
            'repeaters_down' => (int) ($repeaters['offline'] ?? 0),
            'devices' => $total,
            'devices_online' => $online,
            'devices_offline' => (clone $devices)->whereIn('status', ['offline', 'unknown'])->count(),
            'online_pct' => $total ? round($online / $total * 100, 1) : 0,
            'clients_online' => (clone $live)->where('online', true)->count(),
            'clients_offline' => (clone $live)->where('online', false)->count(),
        ];
    }

    /** Dashboard operativo (sección 31): estado de clientes, tickets, personal de campo y red. */
    public function operational(): array
    {
        $counts = $this->statusCounts();
        $net = $this->networkStats();
        $tickets = $this->ticketStats();
        $field = User::query()->where('tenant_id', $this->tid())->where('active', true)->whereIn('role', ['tecnico', 'cobrador']);

        return [
            'active' => (int) ($counts['activo'] ?? 0),
            'suspended' => (int) ($counts['suspendido'] ?? 0),
            'new' => $this->newClients(),
            'disconnected' => $net['clients_offline'],
            'tickets_open' => $tickets['open'],
            'tickets_overdue' => $tickets['overdue'],
            'tickets_critical' => $tickets['critical'],
            // Activo: compartió ubicación desde la app de campo en las últimas 2 horas
            'staff_active' => (clone $field)->where('last_location_at', '>=', now()->subHours(2))->count(),
            'staff_total' => (clone $field)->count(),
            'installs_pending' => InstallationOrder::query()->whereIn('status', ['aceptada', 'programada', 'en_proceso'])->count(),
            'repeaters_online' => $net['repeaters_online'],
            'repeaters_alert' => $net['repeaters_alert'],
        ];
    }

    /** KPIs del panel principal (sección 52 del documento). */
    public function headline(): array
    {
        $counts = $this->statusCounts();
        $mrr = $this->mrr();
        $prevNew = $this->newClientsPrevious();
        $new = $this->newClients();
        $net = $this->networkStats();
        $tickets = $this->ticketStats();
        $del = $this->delinquency();

        return [
            'active' => $counts['activo'] ?? 0,
            'live' => $this->liveClients(),
            'new' => $new,
            'new_change' => $prevNew ? round(($new - $prevNew) / $prevNew * 100) : null,
            'morosos' => $del['count'],
            'morosos_pct' => $del['rate'],
            'mrr' => $mrr,
            'churn' => $this->churn(),
            'churn_prev' => $this->churn(now()->subMonthNoOverflow()),
            'avg_ticket' => $this->averageTicket(),
            'repeaters' => $net['repeaters'],
            'repeaters_alert' => $net['repeaters_alert'],
            'online_pct' => $net['online_pct'],
            'tickets_open' => $tickets['open'],
            'tickets_critical' => $tickets['critical'],
            'collected' => $this->collected(),
            'prospects' => Prospect::query()->whereNotIn('status', ['convertido', 'descartado'])->count(),
            'alerts' => Alert::query()->open()->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Indicadores por periodo (dashboard financiero / BI)
    |--------------------------------------------------------------------------
    */

    /** Expresión SQL de la mensualidad equivalente del plan. */
    protected function monthlyPriceSql(): string
    {
        return "plans.price / CASE plans.periodicity WHEN 'bimonthly' THEN 2 WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END";
    }

    /**
     * Serie histórica de MRR estimado: clientes instalados antes del cierre de cada mes
     * y no cancelados a esa fecha, valuados con su plan actual. El mes en curso usa mrr().
     */
    public function mrrTrend(int $months = 12): array
    {
        $rows = DB::table('clients')->join('plans', 'plans.id', '=', 'clients.plan_id')
            ->where('clients.tenant_id', $this->tid())->whereNull('clients.deleted_at')
            ->whereNotIn('clients.service_status', ['prospecto', 'instalacion_pendiente'])
            ->whereNotNull('clients.installation_date')
            ->selectRaw('clients.installation_date, clients.cancelled_at, '.$this->monthlyPriceSql().' AS monthly')
            ->get();

        $out = [];
        $from = now()->startOfMonth()->subMonths($months - 1);
        for ($m = $from->copy(); $m->lte(now()); $m->addMonth()) {
            $end = $m->copy()->endOfMonth();
            $isCurrent = $m->isSameMonth(now());
            $alive = $rows->filter(fn ($r) => $r->installation_date <= $end->toDateString()
                && ($r->cancelled_at === null || $r->cancelled_at > $end->toDateTimeString()));
            $out[] = [
                'key' => $m->format('Y-m'),
                'label' => ucfirst($m->translatedFormat('M y')),
                'mrr' => round($isCurrent ? $this->mrr() : (float) $alive->sum('monthly'), 2),
                'clients' => $isCurrent ? $this->liveClients() : $alive->count(),
            ];
        }

        return $out;
    }

    /** Cancelaciones en el rango. */
    public function cancellations(Carbon $from, Carbon $to): int
    {
        return Client::query()->whereBetween('cancelled_at', [$from, $to])->count();
    }

    /** Churn del rango: cancelaciones / clientes vigentes al inicio del rango. */
    public function churnBetween(Carbon $from, Carbon $to): float
    {
        $base = Client::query()->where('installation_date', '<', $from->toDateString())
            ->whereNotIn('service_status', ['prospecto', 'instalacion_pendiente'])
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $from))->count();

        return $base > 0 ? round($this->cancellations($from, $to) / $base * 100, 2) : 0;
    }

    /** Monto facturado (cargos no cancelados) con fecha de emisión en el rango. */
    public function billed(Carbon $from, Carbon $to): float
    {
        return (float) DB::table('charges')->where('tenant_id', $this->tid())->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$from, $to])->sum('total');
    }

    /** % de cartera vencida recuperada dentro del rango. */
    public function recoveryRateBetween(Carbon $from, Carbon $to): float
    {
        $recovered = (float) DB::table('payment_allocations')
            ->join('charges', 'charges.id', '=', 'payment_allocations.charge_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.tenant_id', $this->tid())->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->whereRaw('DATE(payments.paid_at) > charges.due_date')
            ->sum('payment_allocations.amount');
        $pending = $this->delinquency()['amount'];

        return ($recovered + $pending) > 0 ? round($recovered / ($recovered + $pending) * 100, 1) : 100;
    }

    /** Ingresos cobrados por plan (plan actual del cliente) en el rango. */
    public function revenueByPlan(?Carbon $from = null, ?Carbon $to = null): array
    {
        return DB::table('payments')->join('clients', 'clients.id', '=', 'payments.client_id')
            ->leftJoin('plans', 'plans.id', '=', 'clients.plan_id')
            ->where('payments.tenant_id', $this->tid())->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->whereBetween('payments.paid_at', [$from ?? now()->startOfMonth(), $to ?? now()])
            ->selectRaw("COALESCE(plans.name, 'Sin plan') name, COALESCE(plans.color, '#94A3B8') color, SUM(payments.amount) total, COUNT(DISTINCT payments.client_id) clients")
            ->groupBy('plans.name', 'plans.color')->orderByDesc('total')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Comisiones de pagos en línea (gateway + plataforma) en el rango. */
    public function commissions(?Carbon $from = null, ?Carbon $to = null): array
    {
        $r = DB::table('payments')->where('tenant_id', $this->tid())->where('status', 'completed')->where('kind', 'payment')
            ->whereBetween('paid_at', [$from ?? now()->startOfMonth(), $to ?? now()])
            ->where(fn ($q) => $q->whereNotNull('gateway')->orWhereIn('method', array_keys(config('wisp.gateways'))))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(amount),0) amount, COALESCE(SUM(gateway_fee),0) gateway_fee, COALESCE(SUM(platform_fee),0) platform_fee, COALESCE(SUM(net_amount),0) net')
            ->first();

        return [
            'count' => (int) $r->n,
            'amount' => (float) $r->amount,
            'gateway_fee' => (float) $r->gateway_fee,
            'platform_fee' => (float) $r->platform_fee,
            'total_fees' => (float) $r->gateway_fee + (float) $r->platform_fee,
            'net' => (float) $r->net,
        ];
    }
}
