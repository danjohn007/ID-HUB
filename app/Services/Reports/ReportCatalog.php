<?php

namespace App\Services\Reports;

use App\Models\Charge;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\NetworkDevice;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Catalog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de reportes (sección 44). Cada reporte define:
 *  - label, group, icon, description
 *  - filters: date (con date_label), zone, plan, status, type  (status/type con opciones)
 *  - query(array $f): Builder|Collection     filas del reporte
 *  - columns(): [Etiqueta => clave|closure]  para ReportExporter
 *  - summary(Builder|Collection $q, array $f): [Etiqueta => valor formateado]  KPIs (vista previa y PDF)
 * Filtros normalizados: from, to (Y-m-d), zone_id, plan_id, status, type.
 */
class ReportCatalog
{
    public const LIVE = ['activo', 'moroso', 'en_revision'];

    public function all(): array
    {
        return [
            'clientes' => [
                'label' => 'Clientes', 'group' => 'Clientes', 'icon' => 'users',
                'description' => 'Padrón de clientes con plan, zona, red y saldo.',
                'filters' => ['date', 'zone', 'plan', 'status'], 'date_label' => 'Fecha de instalación', 'date_default' => false,
                'status' => Catalog::options('client_status'),
            ],
            'morosidad' => [
                'label' => 'Morosidad', 'group' => 'Cobranza', 'icon' => 'exclamation-triangle',
                'description' => 'Clientes con saldo vencido, antigüedad del adeudo y riesgo.',
                'filters' => ['zone', 'plan', 'status'],
                'status' => ['moroso' => 'Moroso', 'suspendido' => 'Suspendido', 'activo' => 'Activo', 'en_revision' => 'En revisión'],
            ],
            'cobranza' => [
                'label' => 'Cobranza', 'group' => 'Cobranza', 'icon' => 'credit-card',
                'description' => 'Cargos emitidos por fecha límite: facturado, pagado y pendiente.',
                'filters' => ['date', 'zone', 'status', 'type'], 'date_label' => 'Fecha límite',
                'status' => Catalog::options('charge_status'), 'type' => config('wisp.charge_types'), 'type_label' => 'Tipo de cargo',
            ],
            'pagos' => [
                'label' => 'Pagos', 'group' => 'Cobranza', 'icon' => 'banknotes',
                'description' => 'Pagos recibidos con comisiones de gateway y plataforma.',
                'filters' => ['date', 'zone', 'status', 'type'], 'date_label' => 'Fecha de pago',
                'status' => Catalog::options('payment_status'), 'type' => config('wisp.payment_methods'), 'type_label' => 'Método',
            ],
            'ingresos' => [
                'label' => 'Ingresos', 'group' => 'Finanzas', 'icon' => 'chart-bar',
                'description' => 'Ingresos mensuales por tipo de cobro, comisiones e importe neto.',
                'filters' => ['date', 'zone'], 'date_label' => 'Fecha de pago', 'date_default' => 'year',
            ],
            'cancelaciones' => [
                'label' => 'Cancelaciones', 'group' => 'Clientes', 'icon' => 'user-minus',
                'description' => 'Bajas del periodo con permanencia y motivo.',
                'filters' => ['date', 'zone', 'plan'], 'date_label' => 'Fecha de cancelación', 'date_default' => 'year',
            ],
            'altas' => [
                'label' => 'Altas', 'group' => 'Clientes', 'icon' => 'user-plus',
                'description' => 'Clientes instalados en el periodo.',
                'filters' => ['date', 'zone', 'plan'], 'date_label' => 'Fecha de instalación',
            ],
            'tickets' => [
                'label' => 'Tickets', 'group' => 'Operación', 'icon' => 'ticket',
                'description' => 'Tickets de soporte con SLA y tiempo de resolución.',
                'filters' => ['date', 'zone', 'status', 'type'], 'date_label' => 'Fecha de creación',
                'status' => Catalog::options('ticket_status'), 'type' => Catalog::options('ticket_priorities'), 'type_label' => 'Prioridad',
            ],
            'tecnicos' => [
                'label' => 'Técnicos', 'group' => 'Operación', 'icon' => 'wrench-screwdriver',
                'description' => 'Productividad por técnico: tickets, SLA e instalaciones.',
                'filters' => ['date'], 'date_label' => 'Periodo',
            ],
            'inventario' => [
                'label' => 'Inventario', 'group' => 'Operación', 'icon' => 'archive-box',
                'description' => 'Existencias, mínimos, máximos y valor del inventario.',
                'filters' => ['status', 'type'],
                'status' => ['bajo' => 'Bajo mínimo', 'agotado' => 'Agotado', 'ok' => 'Existencia normal'], 'status_label' => 'Existencia',
                'type' => config('wisp.inventory_categories'), 'type_label' => 'Categoría',
            ],
            'equipos' => [
                'label' => 'Equipos', 'group' => 'Red', 'icon' => 'server-stack',
                'description' => 'Equipos instalados: cliente → CPE → repetidor → torre.',
                'filters' => ['status', 'type'],
                'status' => Catalog::options('device_status'), 'type' => config('wisp.device_types'), 'type_label' => 'Tipo',
            ],
            'red' => [
                'label' => 'Red', 'group' => 'Red', 'icon' => 'signal',
                'description' => 'Estado y métricas de los equipos: CPU, memoria, latencia, pérdida y señal.',
                'filters' => ['status', 'type'],
                'status' => Catalog::options('device_status'), 'type' => config('wisp.device_types'), 'type_label' => 'Tipo',
            ],
            'repetidores' => [
                'label' => 'Repetidores', 'group' => 'Red', 'icon' => 'wifi',
                'description' => 'Capacidad, clientes, utilización y estado de cada repetidor.',
                'filters' => ['zone', 'status'],
                'status' => ['online' => 'Operativo', 'warning' => 'Advertencia', 'offline' => 'Fallo', 'maintenance' => 'Mantenimiento'],
            ],
            'consumo' => [
                'label' => 'Consumo', 'group' => 'Red', 'icon' => 'arrows-up-down',
                'description' => 'Consumo de datos del mes por cliente y uso del límite del plan.',
                'filters' => ['zone', 'plan'],
            ],
            'referidos' => [
                'label' => 'Referidos', 'group' => 'Crecimiento', 'icon' => 'user-group',
                'description' => 'Programa de referidos: códigos, validación y recompensas.',
                'filters' => ['date', 'status'], 'date_label' => 'Fecha de registro', 'date_default' => 'year',
                'status' => Catalog::options('referral_status'),
            ],
        ];
    }

    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** Rango de fechas por defecto de un reporte (Y-m-d). */
    public function defaultDates(string $key): array
    {
        $def = $this->get($key);
        $mode = $def['date_default'] ?? 'month';
        if ($mode === false || ! in_array('date', $def['filters'] ?? [], true)) {
            return ['', ''];
        }

        return $mode === 'year'
            ? [now()->subMonthsNoOverflow(11)->startOfMonth()->toDateString(), now()->toDateString()]
            : [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }

    protected function range(array $f): ?array
    {
        if (empty($f['from']) && empty($f['to'])) {
            return null;
        }
        try {
            $from = ! empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : Carbon::create(2000);
            $to = ! empty($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    protected function byClientZone(Builder $q, array $f): Builder
    {
        return $q->when($f['zone_id'] ?? null, fn ($q, $z) => $q->whereHas('client', fn ($c) => $c->where('zone_id', $z)));
    }

    /** Filas del reporte (Builder sin ejecutar o colección ya calculada). */
    public function query(string $key, array $f): Builder|Collection
    {
        $range = $this->range($f);
        $zone = $f['zone_id'] ?? null;
        $plan = $f['plan_id'] ?? null;
        $status = $f['status'] ?? null;
        $type = $f['type'] ?? null;

        return match ($key) {
            'clientes' => Client::query()->with(['plan:id,name,price', 'zone:id,name', 'repeater:id,code'])
                ->when($zone, fn ($q) => $q->where('zone_id', $zone))->when($plan, fn ($q) => $q->where('plan_id', $plan))
                ->when($status, fn ($q) => $q->where('service_status', $status))
                ->when($range, fn ($q) => $q->whereBetween('installation_date', [$range[0]->toDateString(), $range[1]->toDateString()]))
                ->orderBy('code'),

            'morosidad' => Client::query()->with(['plan:id,name', 'zone:id,name'])
                ->select('clients.*')
                ->selectSub(fn ($s) => $s->from('charges')->whereColumn('charges.client_id', 'clients.id')->whereIn('status', ['pending', 'partial'])
                    ->where('due_date', '<', now()->toDateString())->selectRaw('COALESCE(SUM(total - paid_amount), 0)'), 'overdue_amount')
                ->selectSub(fn ($s) => $s->from('charges')->whereColumn('charges.client_id', 'clients.id')->whereIn('status', ['pending', 'partial'])
                    ->where('due_date', '<', now()->toDateString())->selectRaw('MIN(due_date)'), 'oldest_due')
                ->whereHas('charges', fn ($c) => $c->whereIn('status', ['pending', 'partial'])->where('due_date', '<', now()->toDateString()))
                ->whereNotIn('service_status', ['cancelado'])
                ->when($zone, fn ($q) => $q->where('zone_id', $zone))->when($plan, fn ($q) => $q->where('plan_id', $plan))
                ->when($status, fn ($q) => $q->where('service_status', $status))
                ->orderByDesc('overdue_amount'),

            'cobranza' => $this->byClientZone(Charge::query()->with(['client:id,code,first_name,last_name,zone_id', 'client.zone:id,name']), $f)
                ->when($range, fn ($q) => $q->whereBetween('due_date', [$range[0]->toDateString(), $range[1]->toDateString()]))
                ->when($status, fn ($q) => $q->where('status', $status))->when($type, fn ($q) => $q->where('type', $type))
                ->orderByDesc('due_date')->orderByDesc('id'),

            'pagos' => $this->byClientZone(Payment::query()->with(['client:id,code,first_name,last_name,zone_id', 'client.zone:id,name', 'receivedBy:id,name', 'collector:id,name']), $f)
                ->when($range, fn ($q) => $q->whereBetween('paid_at', $range))
                ->when($status, fn ($q) => $q->where('status', $status))->when($type, fn ($q) => $q->where('method', $type))
                ->orderByDesc('paid_at'),

            'ingresos' => $this->income($range, $zone),

            'cancelaciones' => Client::query()->with(['plan:id,name,price', 'zone:id,name'])->whereNotNull('cancelled_at')
                ->when($range, fn ($q) => $q->whereBetween('cancelled_at', $range))
                ->when($zone, fn ($q) => $q->where('zone_id', $zone))->when($plan, fn ($q) => $q->where('plan_id', $plan))
                ->orderByDesc('cancelled_at'),

            'altas' => Client::query()->with(['plan:id,name,price', 'zone:id,name', 'repeater:id,code', 'technician:id,name'])->whereNotNull('installation_date')
                ->when($range, fn ($q) => $q->whereBetween('installation_date', [$range[0]->toDateString(), $range[1]->toDateString()]))
                ->when($zone, fn ($q) => $q->where('zone_id', $zone))->when($plan, fn ($q) => $q->where('plan_id', $plan))
                ->orderByDesc('installation_date'),

            'tickets' => $this->byClientZone(Ticket::query()->with(['client:id,code,first_name,last_name,zone_id', 'technician:id,name']), $f)
                ->when($range, fn ($q) => $q->whereBetween('created_at', $range))
                ->when($status, fn ($q) => $q->where('status', $status))->when($type, fn ($q) => $q->where('priority', $type))
                ->orderByDesc('created_at'),

            'tecnicos' => $this->technicians($range),

            'inventario' => InventoryItem::query()
                ->when($type, fn ($q) => $q->where('category', $type))
                ->when($status === 'bajo', fn ($q) => $q->whereColumn('stock', '<=', 'min_stock'))
                ->when($status === 'agotado', fn ($q) => $q->where('stock', '<=', 0))
                ->when($status === 'ok', fn ($q) => $q->whereColumn('stock', '>', 'min_stock'))
                ->orderBy('category')->orderBy('name'),

            'equipos' => NetworkDevice::query()->with(['repeater:id,code', 'client:id,code,first_name,last_name', 'tower:id,code'])
                ->when($type, fn ($q) => $q->where('type', $type))->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('type')->orderBy('name'),

            'red' => NetworkDevice::query()->with(['repeater:id,code'])->whereNotIn('status', $status ? [] : ['retired', 'in_stock'])
                ->when($type, fn ($q) => $q->where('type', $type))->when($status, fn ($q) => $q->where('status', $status))
                ->orderByRaw("FIELD(status, 'offline', 'warning', 'unknown', 'maintenance', 'online')")->orderBy('name'),

            'repetidores' => Repeater::query()->withLoad()->with(['zone:id,name', 'tower:id,code'])
                ->when($zone, fn ($q) => $q->where('zone_id', $zone))->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('code'),

            'consumo' => Client::query()->with(['plan:id,name,download_mbps,upload_mbps,data_cap_gb', 'zone:id,name', 'repeater:id,code'])
                ->whereIn('service_status', Client::BILLABLE)
                ->when($zone, fn ($q) => $q->where('zone_id', $zone))->when($plan, fn ($q) => $q->where('plan_id', $plan))
                ->orderByDesc('month_usage_gb'),

            'referidos' => Referral::query()->with(['referrer:id,code,first_name,last_name', 'referred:id,code,first_name,last_name'])
                ->when($range, fn ($q) => $q->whereBetween('created_at', $range))->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('created_at'),

            default => collect(),
        };
    }

    /** Ingresos agrupados por mes. */
    protected function income(?array $range, $zone): Collection
    {
        $online = array_keys(config('wisp.gateways'));
        $in = fn (array $methods) => "'".implode("','", $methods)."'";

        return DB::table('payments')->join('clients', 'clients.id', '=', 'payments.client_id')
            ->where('payments.tenant_id', tenant()->id)->where('payments.status', 'completed')->where('payments.kind', 'payment')
            ->when($range, fn ($q) => $q->whereBetween('payments.paid_at', $range))
            ->when($zone, fn ($q) => $q->where('clients.zone_id', $zone))
            ->selectRaw("DATE_FORMAT(payments.paid_at, '%Y-%m') ym, COUNT(*) n, SUM(payments.amount) total,
                SUM(CASE WHEN payments.method = 'efectivo' THEN payments.amount ELSE 0 END) cash,
                SUM(CASE WHEN payments.method IN ('transferencia','deposito','tarjeta') THEN payments.amount ELSE 0 END) bank,
                SUM(CASE WHEN payments.method IN (".$in($online).') THEN payments.amount ELSE 0 END) online,
                SUM(payments.gateway_fee) gateway_fee, SUM(payments.platform_fee) platform_fee, SUM(payments.net_amount) net')
            ->groupBy('ym')->orderByDesc('ym')->get()
            ->map(function ($r) {
                $r->label = ucfirst(Carbon::createFromFormat('Y-m-d', $r->ym.'-01')->translatedFormat('F Y'));

                return $r;
            });
    }

    /** Productividad por técnico. */
    protected function technicians(?array $range): Collection
    {
        $tid = tenant()->id;
        $tickets = DB::table('tickets')->where('tenant_id', $tid)->whereNotNull('technician_id')
            ->when($range, fn ($q) => $q->whereBetween('created_at', $range))
            ->selectRaw("technician_id, COUNT(*) assigned,
                SUM(CASE WHEN status IN ('resuelto','cerrado') THEN 1 ELSE 0 END) resolved,
                SUM(CASE WHEN sla_breached = 1 THEN 1 ELSE 0 END) breached,
                AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) avg_minutes")
            ->groupBy('technician_id')->get()->keyBy('technician_id');
        $installs = DB::table('installation_orders')->where('tenant_id', $tid)->whereNotNull('technician_id')
            ->whereIn('status', ['instalada', 'activada'])
            ->when($range, fn ($q) => $q->whereBetween('completed_at', $range))
            ->selectRaw('technician_id, COUNT(*) n')->groupBy('technician_id')->pluck('n', 'technician_id');

        return User::forTenant()->role(['tecnico', 'supervisor'])->orderBy('name')->get()->map(function ($u) use ($tickets, $installs) {
            $t = $tickets->get($u->id);
            $resolved = (int) ($t->resolved ?? 0);

            return (object) [
                'name' => $u->name, 'role' => $u->roleLabel(), 'active' => $u->active, 'on_call' => $u->on_call,
                'assigned' => (int) ($t->assigned ?? 0), 'resolved' => $resolved, 'breached' => (int) ($t->breached ?? 0),
                'sla_ok' => ($t->assigned ?? 0) > 0 ? round((1 - ($t->breached / $t->assigned)) * 100, 1) : null,
                'avg_hours' => isset($t->avg_minutes) ? round($t->avg_minutes / 60, 1) : null,
                'installations' => (int) ($installs[$u->id] ?? 0),
            ];
        });
    }

    public function columns(string $key): array
    {
        $name = fn ($rel) => fn ($r) => $r->{$rel} ? trim($r->{$rel}->code.' · '.$r->{$rel}->full_name) : '';
        $d = fn ($attr, $fmt = 'd/m/Y') => fn ($r) => $r->{$attr}?->format($fmt);

        return match ($key) {
            'clientes' => [
                'Código' => 'code', 'Nombre' => 'full_name', 'Teléfono' => 'phone', 'Correo' => 'email', 'Dirección' => 'address',
                'Zona' => 'zone.name', 'Plan' => 'plan.name', 'Repetidor' => 'repeater.code', 'IP' => 'ip_address',
                'Estado' => fn ($c) => Catalog::label('client_status', $c->service_status), 'Saldo' => fn ($c) => (float) $c->balance,
                'Instalación' => $d('installation_date'),
            ],
            'morosidad' => [
                'Código' => 'code', 'Cliente' => 'full_name', 'Teléfono' => 'phone', 'Zona' => 'zone.name', 'Plan' => 'plan.name',
                'Estado' => fn ($c) => Catalog::label('client_status', $c->service_status),
                'Saldo vencido' => fn ($c) => (float) $c->overdue_amount, 'Saldo total' => fn ($c) => (float) $c->balance,
                'Vencido desde' => fn ($c) => $c->oldest_due ? Carbon::parse($c->oldest_due)->format('d/m/Y') : '',
                'Días de atraso' => fn ($c) => $c->oldest_due ? (int) Carbon::parse($c->oldest_due)->diffInDays(now()) : 0,
                'Riesgo' => fn ($c) => Catalog::label('risk_levels', $c->risk_level),
            ],
            'cobranza' => [
                'Folio' => 'folio', 'Cliente' => $name('client'), 'Zona' => 'client.zone.name', 'Concepto' => 'description',
                'Tipo' => fn ($c) => config("wisp.charge_types.{$c->type}", $c->type), 'Emisión' => $d('created_at'), 'Fecha límite' => $d('due_date'),
                'Total' => fn ($c) => (float) $c->total, 'Pagado' => fn ($c) => (float) $c->paid_amount,
                'Saldo' => fn ($c) => $c->status === 'cancelled' ? 0.0 : round((float) $c->total - (float) $c->paid_amount, 2),
                'Estado' => fn ($c) => Catalog::label('charge_status', $c->status),
            ],
            'pagos' => [
                'Folio' => 'folio', 'Fecha' => $d('paid_at', 'd/m/Y H:i'), 'Cliente' => $name('client'), 'Zona' => 'client.zone.name',
                'Método' => fn ($p) => $p->methodLabel(), 'Referencia' => 'reference', 'Importe' => fn ($p) => (float) $p->amount,
                'Comisión gateway' => fn ($p) => (float) $p->gateway_fee, 'Comisión plataforma' => fn ($p) => (float) $p->platform_fee,
                'Neto' => fn ($p) => (float) $p->net_amount, 'Estado' => fn ($p) => Catalog::label('payment_status', $p->status),
                'Recibió' => fn ($p) => $p->collector?->name ?? $p->receivedBy?->name,
            ],
            'ingresos' => [
                'Mes' => 'label', 'Pagos' => 'n', 'Total cobrado' => fn ($r) => (float) $r->total, 'Efectivo' => fn ($r) => (float) $r->cash,
                'Transferencia / depósito / tarjeta' => fn ($r) => (float) $r->bank, 'En línea' => fn ($r) => (float) $r->online,
                'Comisión gateway' => fn ($r) => (float) $r->gateway_fee, 'Comisión plataforma' => fn ($r) => (float) $r->platform_fee, 'Neto' => fn ($r) => (float) $r->net,
            ],
            'cancelaciones' => [
                'Código' => 'code', 'Cliente' => 'full_name', 'Zona' => 'zone.name', 'Plan' => 'plan.name', 'Mensualidad' => fn ($c) => (float) $c->plan?->price,
                'Alta' => $d('installation_date'), 'Baja' => $d('cancelled_at'),
                'Permanencia (meses)' => fn ($c) => $c->installation_date && $c->cancelled_at ? (int) $c->installation_date->diffInMonths($c->cancelled_at) : null,
                'Motivo' => 'cancel_reason',
            ],
            'altas' => [
                'Código' => 'code', 'Cliente' => 'full_name', 'Teléfono' => 'phone', 'Zona' => 'zone.name', 'Plan' => 'plan.name',
                'Mensualidad' => fn ($c) => (float) $c->plan?->price, 'Repetidor' => 'repeater.code', 'Técnico' => 'technician.name',
                'Instalación' => $d('installation_date'), 'Estado' => fn ($c) => Catalog::label('client_status', $c->service_status),
            ],
            'tickets' => [
                'Folio' => 'folio', 'Fecha' => $d('created_at', 'd/m/Y H:i'), 'Cliente' => $name('client'),
                'Categoría' => fn ($t) => config("wisp.ticket_categories.{$t->category}", $t->category),
                'Prioridad' => fn ($t) => Catalog::label('ticket_priorities', $t->priority), 'Estado' => fn ($t) => Catalog::label('ticket_status', $t->status),
                'Técnico' => 'technician.name', 'SLA' => fn ($t) => $t->sla_breached || ($t->isOpen() && $t->sla_due_at?->isPast()) ? 'Vencido' : 'En tiempo',
                'Horas de resolución' => fn ($t) => $t->resolved_at ? round($t->created_at->diffInMinutes($t->resolved_at) / 60, 1) : null,
            ],
            'tecnicos' => [
                'Técnico' => 'name', 'Rol' => 'role', 'Tickets asignados' => 'assigned', 'Resueltos' => 'resolved', 'SLA vencidos' => 'breached',
                'Cumplimiento SLA %' => 'sla_ok', 'Horas prom. resolución' => 'avg_hours', 'Instalaciones' => 'installations',
                'De guardia' => fn ($r) => $r->on_call ? 'Sí' : 'No',
            ],
            'inventario' => [
                'SKU' => 'sku', 'Artículo' => 'name', 'Categoría' => fn ($i) => config("wisp.inventory_categories.{$i->category}", $i->category),
                'Marca / modelo' => fn ($i) => trim($i->brand.' '.$i->model), 'Existencia' => fn ($i) => (float) $i->stock, 'Unidad' => 'unit',
                'Mínimo' => fn ($i) => (float) $i->min_stock, 'Máximo' => fn ($i) => $i->max_stock !== null ? (float) $i->max_stock : null,
                'Costo unitario' => fn ($i) => (float) $i->unit_cost, 'Valor' => fn ($i) => round((float) $i->stock * (float) $i->unit_cost, 2),
                'Estado' => fn ($i) => ['danger' => 'Agotado', 'warning' => 'Bajo mínimo', 'info' => 'Sobre máximo', 'success' => 'Normal'][$i->stockState()],
            ],
            'equipos' => [
                'Equipo' => 'name', 'Tipo' => fn ($d) => config("wisp.device_types.{$d->type}", $d->type), 'Fabricante' => 'manufacturer', 'Modelo' => 'model',
                'Serie' => 'serial', 'MAC' => 'mac', 'IP' => 'ip', 'Firmware' => 'firmware', 'Estado' => fn ($d) => Catalog::label('device_status', $d->status),
                'Cliente' => $name('client'), 'Repetidor' => 'repeater.code', 'Torre' => 'tower.code', 'Instalado' => $d('installed_at'),
            ],
            'red' => [
                'Equipo' => 'name', 'Tipo' => fn ($d) => config("wisp.device_types.{$d->type}", $d->type), 'IP' => 'ip', 'Repetidor' => 'repeater.code',
                'Estado' => fn ($d) => Catalog::label('device_status', $d->status), 'CPU %' => 'cpu', 'Memoria %' => 'memory', 'Latencia ms' => 'latency_ms',
                'Pérdida %' => 'packet_loss', 'Señal dBm' => 'signal_dbm', 'Uptime' => 'uptime', 'Última comunicación' => $d('last_seen_at', 'd/m/Y H:i'),
            ],
            'repetidores' => [
                'Código' => 'code', 'Nombre' => 'name', 'Zona' => 'zone.name', 'Torre' => 'tower.code', 'Capacidad' => 'capacity',
                'Clientes' => fn ($r) => $r->clientLoad(), 'En línea' => fn ($r) => (int) $r->online_clients_count, 'Utilización %' => fn ($r) => $r->utilization(),
                'Umbral %' => 'alert_threshold', 'Cobertura km' => fn ($r) => (float) $r->coverage_radius_km,
                'Estado' => fn ($r) => Catalog::label('device_status', $r->status), 'Costo mensual' => fn ($r) => (float) $r->monthly_cost,
            ],
            'consumo' => [
                'Código' => 'code', 'Cliente' => 'full_name', 'Plan' => 'plan.name', 'Velocidad' => fn ($c) => $c->plan?->speedLabel(),
                'Límite GB' => fn ($c) => $c->plan?->data_cap_gb, 'Consumo GB' => fn ($c) => (float) $c->month_usage_gb,
                '% del límite' => fn ($c) => $c->plan?->data_cap_gb ? round((float) $c->month_usage_gb / $c->plan->data_cap_gb * 100, 1) : null,
                'Zona' => 'zone.name', 'Repetidor' => 'repeater.code', 'Estado' => fn ($c) => Catalog::label('client_status', $c->service_status),
            ],
            'referidos' => [
                'Código' => 'code', 'Referente' => $name('referrer'), 'Referido' => $name('referred'), 'Estado' => fn ($r) => Catalog::label('referral_status', $r->status),
                'Recompensa' => fn ($r) => $r->reward_type ? config("wisp.reward_types.{$r->reward_type}", $r->reward_type) : '',
                'Valor' => fn ($r) => (float) $r->reward_value, 'Registro' => $d('created_at'), 'Validado' => $d('validated_at'), 'Recompensado' => $d('rewarded_at'),
            ],
            default => [],
        };
    }

    /** KPIs del reporte (se muestran arriba de la vista previa y en el PDF). */
    public function summary(string $key, Builder|Collection $q, array $f): array
    {
        $c = fn () => $q instanceof Builder ? (clone $q)->reorder()->setEagerLoads([]) : $q;

        return match ($key) {
            'clientes' => [
                'Clientes' => number_format($c()->count()),
                'Con servicio vigente' => number_format($c()->whereIn('service_status', self::LIVE)->count()),
                'Saldo por cobrar' => money($c()->sum('balance')),
            ],
            'morosidad' => (function () use ($q) {
                $rows = (clone $q)->get();

                return [
                    'Clientes con adeudo' => number_format($rows->count()),
                    'Saldo vencido' => money($rows->sum('overdue_amount')),
                    'Atraso promedio' => round($rows->avg(fn ($r) => $r->oldest_due ? Carbon::parse($r->oldest_due)->diffInDays(now()) : 0) ?? 0).' días',
                ];
            })(),
            'cobranza' => (function () use ($c) {
                $s = $c()->where('status', '!=', 'cancelled')->selectRaw('COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(paid_amount),0) p')->first();

                return [
                    'Cargos' => number_format($s->n), 'Facturado' => money($s->t), 'Cobrado' => money($s->p), 'Pendiente' => money($s->t - $s->p),
                    '% cobranza' => $s->t > 0 ? pct($s->p / $s->t * 100) : '—',
                ];
            })(),
            'pagos' => (function () use ($c) {
                $s = $c()->where('status', 'completed')->selectRaw('COUNT(*) n, COALESCE(SUM(amount),0) a, COALESCE(SUM(gateway_fee + platform_fee),0) f, COALESCE(SUM(net_amount),0) net')->first();

                return ['Pagos aplicados' => number_format($s->n), 'Importe' => money($s->a), 'Comisiones' => money($s->f), 'Neto' => money($s->net)];
            })(),
            'ingresos' => [
                'Cobrado' => money($q->sum('total')), 'En línea' => money($q->sum('online')),
                'Comisiones' => money($q->sum('gateway_fee') + $q->sum('platform_fee')), 'Neto' => money($q->sum('net')),
            ],
            'cancelaciones' => (function () use ($q) {
                $rows = (clone $q)->get();

                return [
                    'Cancelaciones' => number_format($rows->count()),
                    'MRR perdido' => money($rows->sum(fn ($r) => (float) $r->plan?->price)),
                    'Permanencia promedio' => round($rows->avg(fn ($r) => $r->installation_date && $r->cancelled_at ? $r->installation_date->diffInMonths($r->cancelled_at) : 0) ?? 0, 1).' meses',
                ];
            })(),
            'altas' => (function () use ($q) {
                $rows = (clone $q)->get();

                return ['Altas' => number_format($rows->count()), 'MRR nuevo' => money($rows->sum(fn ($r) => (float) $r->plan?->price))];
            })(),
            'tickets' => [
                'Tickets' => number_format($c()->count()),
                'Resueltos' => number_format($c()->whereIn('status', ['resuelto', 'cerrado'])->count()),
                'SLA vencido' => number_format($c()->where('sla_breached', true)->count()),
                'Horas prom. resolución' => (string) round(((float) $c()->whereNotNull('resolved_at')->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, resolved_at)'))) / 60, 1),
            ],
            'tecnicos' => [
                'Técnicos' => number_format($q->count()), 'Tickets resueltos' => number_format($q->sum('resolved')),
                'Instalaciones' => number_format($q->sum('installations')),
            ],
            'inventario' => [
                'Artículos' => number_format($c()->count()),
                'Valor del inventario' => money($c()->sum(DB::raw('stock * unit_cost'))),
                'Bajo mínimo' => number_format($c()->whereColumn('stock', '<=', 'min_stock')->count()),
            ],
            'equipos' => [
                'Equipos' => number_format($c()->count()),
                'Operativos' => number_format($c()->where('status', 'online')->count()),
                'Asignados a clientes' => number_format($c()->whereNotNull('client_id')->count()),
            ],
            'red' => (function () use ($c) {
                $total = $c()->count();
                $online = $c()->where('status', 'online')->count();

                return [
                    'Equipos' => number_format($total), 'En línea' => $total ? pct($online / $total * 100) : '—',
                    'Latencia promedio' => round((float) $c()->avg('latency_ms'), 1).' ms', 'Pérdida promedio' => pct((float) $c()->avg('packet_loss')),
                ];
            })(),
            'repetidores' => (function () use ($q) {
                $rows = (clone $q)->get();

                return [
                    'Repetidores' => number_format($rows->count()),
                    'Utilización promedio' => pct($rows->avg(fn ($r) => $r->utilization()) ?? 0),
                    'Saturados' => number_format($rows->filter(fn ($r) => $r->utilization() >= $r->alert_threshold)->count()),
                    'Fuera de servicio' => number_format($rows->where('status', 'offline')->count()),
                ];
            })(),
            'consumo' => [
                'Clientes' => number_format($c()->count()),
                'Consumo total' => number_format((float) $c()->sum('month_usage_gb') / 1024, 2).' TB',
                'Promedio por cliente' => number_format((float) $c()->avg('month_usage_gb'), 1).' GB',
            ],
            'referidos' => [
                'Referidos' => number_format($c()->count()),
                'Recompensados' => number_format($c()->where('status', 'recompensado')->count()),
                'Valor de recompensas' => money($c()->where('status', 'recompensado')->sum('reward_value')),
            ],
            default => [],
        };
    }
}
